<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator;

use Amp\Future;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use ScriptFUSION\Async\Throttle\DualThrottle;
use ScriptFUSION\Async\Throttle\Throttle;
use ScriptFUSION\Porter\Import\Import;
use ScriptFUSION\Porter\Porter;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\CuratorList\CuratorList;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\CuratorList\DeleteCuratorListApp;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\CuratorList\PatchCuratorListAppOrder;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\CuratorList\PutCuratorList;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\CuratorList\PutCuratorListApp;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\CuratorReview;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\CuratorSession;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\PutCuratorReview;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\RecommendationState;
use ScriptFUSION\Retry\FailingTooHardException;
use ScriptFUSION\Steam250\Curator\Import\GetCuratorListsImport;
use ScriptFUSION\Steam250\Curator\Import\ListObsoleteReviewsImport;
use ScriptFUSION\Steam250\Curator\Import\PutCuratorReviewImport;
use function Amp\Future\await;

final class ReviewSynchronizer
{
    private const MAX_LIST_LENGTH = 100;

    private Throttle $throttle;

    public function __construct(
        private readonly CuratorSession $session,
        private readonly int $curatorId,
        private readonly Porter $porter,
        private readonly Connection $database,
        private readonly LoggerInterface $logger
    ) {
        $this->throttle = new DualThrottle(7, 6);
    }

    /**
     * 1. Fetch all ranking lists.
     * 2. Synchronize each app's review.
     * 3. Archive obsolete reviews.
     * 4. Synchronize lists.
     */
    public function synchronize(): bool
    {
        $this->logger->info('Beginning review synchronization...');

        $lists = $this->fetchLists();

        $syncReviewsStatus = $this->synchronizeReviews();

        $this->logger->info(
            'Summary: Updated: ' . count($syncReviewsStatus->getSucceeded())
                . ', Skipped: ' . count($syncReviewsStatus->getSkipped())
                . ', Errors: ' . count($syncReviewsStatus->getErrors())
                . '.'
        );

        $this->archiveObsoleteReviews(array_column($syncReviewsStatus->getSucceeded(), 'id'));

        $this->synchronizeLists($lists, $syncReviewsStatus);

        return count($syncReviewsStatus->getErrors()) === 0;
    }

    /**
     * Fetches each ranking list, containing all the reviewed app IDs.
     */
    private function fetchLists(): array
    {
        $listCollection = $this->porter->import(
            new GetCuratorListsImport($this->session, $this->curatorId)
        );

        $lists = [];
        foreach ($listCollection as $list) {
            $lists[$list['title']] = $list;
        }

        self::validateLists($lists);

        return $lists;
    }

    private static function validateLists(array $lists): void
    {
        foreach (Ranking::members() as $ranking) {
            if (!isset($lists[$ranking->getCanonicalName()])) {
                throw new \RuntimeException("Required ranking not found: \"{$ranking->getCanonicalName()}\".");
            }
        }
    }

    private function synchronizeReviews(): SynchronizeReviewsResult
    {
        $reviews = $this->putReviews();
        $count = 0;
        $updated = $skipped = $errors = [];

        foreach (Future::iterate($reviews) as $futureReview) {
            [$response, $app] = $futureReview->await();

            $logContext = [
                'app' => $app,
                'count' => ++$count,
                'total' => \count($reviews),
                'throttle' => $this->throttle,
            ];

            if (!isset($response['success'])) {
                $this->logger->warning("[$app[list_id]] Skipped %app%: \"$response\"", $logContext);

                $skipped[] = $app;
            } elseif ($response['success'] === 1) {
                $this->logger->info("[$app[list_id]] Synced %app% OK.", $logContext);

                $updated[] = $app;
            } else {
                $this->logger->error(
                    "[$app[list_id]] Failed to sync %app%! Error code: $response[success].",
                    $logContext
                );

                $errors[] = $app;
            }
        }

        return new SynchronizeReviewsResult($updated, $skipped, $errors);
    }

    private function putReviews(): CountableIterator
    {
        $apps = $this->fetchAllApps();

        return new CountableIterator(
            \count($apps),
            (function () use ($apps): \Generator {
                foreach ($apps as $app) {
                    yield $this->throttle->async(
                        function () use ($app): array {
                            try {
                                return [
                                    $this->porter->importOne(
                                        new PutCuratorReviewImport($this->session, $this->curatorId, $app)
                                    ),
                                    $app,
                                ];
                            } catch (\Exception $exception) {
                                // Error too serious to ignore.
                                if ($exception instanceof FailingTooHardException) {
                                    throw $exception;
                                }

                                return [$exception->getMessage(), $app];
                            }
                        }
                    );
                }
            })()
        );
    }

    /**
     * Fetches all apps that appear on any ranking.
     *
     * Duplicate apps are filtered, leaving only the app whose ranking has the highest priority
     * (determined by smallest priority value).
     *
     * @return array List of apps.
     */
    private function fetchAllApps(): array
    {
        $cteFragment = self::createRankingPriorityCteFragment();

        return $this->database->fetchAllAssociative("
            WITH p(id, priority) AS (VALUES $cteFragment)
            SELECT rank.*, app.id, name, total_reviews, positive_reviews, MIN(priority)
            FROM rank
            INNER JOIN app ON rank.app_id = app.id
            INNER JOIN p ON p.id = list_id
            GROUP BY app_id
            ORDER BY priority, rank DESC
        ");
    }

    private static function createRankingPriorityCteFragment(): string
    {
        return implode(',', array_map(fn ($ranking) => "('$ranking', {$ranking->getPriority()})", Ranking::members()));
    }

    /**
     * Fetches a list of ordered app IDs for the specified ranking.
     *
     * @param Ranking $rankingName Ranking.
     *
     * @return int[] List of app IDs.
     */
    private function fetchRankingApps(Ranking $rankingName): array
    {
        return array_map(
            fn (array $row) => $row['app_id'],
            $this->database->fetchAllAssociative("
                SELECT app_id
                FROM rank
                WHERE list_id = '$rankingName'
                ORDER BY rank
            ")
        );
    }

    /**
     * Archives obsolete reviews. Obsolete reviews are those marked as "recommended" but which no longer appear on any
     * ranking list. Reviews are archived by changing their description and changing the recommendation state to
     * "informational".
     */
    private function archiveObsoleteReviews(array $freshAppIds): void
    {
        $staleApps = $this->porter->import(
            new ListObsoleteReviewsImport($this->session, $this->curatorId, $freshAppIds)
        );

        foreach ($staleApps as $stale) {
            $this->logger->info(
                "Archiving obsolete review: #$stale[appid] $stale[app_name]...",
                ['throttle' => $this->throttle]
            );

            $ranking = Ranking::fromUrl($stale['recommendation']['link_url']);

            $this->throttle->async(
                fn () => $this->porter->importOne(new Import(new PutCuratorReview(
                    $this->session,
                    $this->curatorId,
                    new CuratorReview(
                        $stale['appid'],
                        "$stale[app_name] was a member of the {$ranking->getCanonicalName()} until "
                            . date('F jS, Y.'),
                        RecommendationState::INFORMATIONAL()
                    )
                )))
            )->await();
        }

        isset($stale) || $this->logger->info('No obsolete reviews found.');
    }

    private function synchronizeLists(array $lists, SynchronizeReviewsResult $syncReviewsStatus): void
    {
        foreach (Ranking::members() as $ranking) {
            $list = $lists[$ranking->getCanonicalName()];

            $curatorList = $ranking->toCuratorList();
            $curatorList->setListId($list['id']);
            $curatorList->setAppIds($list['appids']);

            $rankingApps = $this->fetchRankingApps($ranking);
            $validApps = array_intersect(
                $rankingApps,
                array_column($syncReviewsStatus->getSucceeded(), 'id')
            );

            $operations = $this->reconcileListApps($curatorList, $validApps);

            foreach (Future::iterate($operations) as $operation) {
                $response = $operation->await();

                if (!isset($response['success'])) {
                    throw new \RuntimeException('An operation encountered an unknown error.');
                }
                if ($response['success'] !== 1) {
                    throw new \RuntimeException(
                        "An operation did not succeed: Steam error code: $response[success]."
                    );
                }
            }
        }
    }

    private function reconcileListApps(CuratorList $curatorList, array $newAppIds): \Iterator
    {
        // Clamp list length to max length.
        array_splice($newAppIds, self::MAX_LIST_LENGTH);

        $add = array_diff($newAppIds, $curatorList->getAppIds());
        $remove = array_diff($curatorList->getAppIds(), $newAppIds);

        $count = 0;
        $total = count($remove);
        $logContext = ['throttle' => $this->throttle, 'count' => &$count, 'total' => &$total];

        // Push removals. Removals must be pushed before adds to avoid overflowing the list length limit.
        foreach ($remove as $appId) {
            ++$count;

            $this->logger->info("[{$curatorList->getTitle()}] Removing: #$appId...", $logContext);

            yield $this->throttle->async(fn () => $this->porter->importOne(new Import(
                new DeleteCuratorListApp($this->session, $this->curatorId, $curatorList->getListId(), $appId)
            )));
        }

        $this->waitForPending();

        $count = 0;
        $total = count($add);
        // Push additions.
        foreach ($add as $appId) {
            ++$count;

            $this->logger->info("[{$curatorList->getTitle()}] Adding: #$appId...", $logContext);

            yield $this->throttle->async(fn () => $this->porter->importOne(new Import(
                new PutCuratorListApp($this->session, $this->curatorId, $curatorList->getListId(), $appId)
            )));
        }

        $this->waitForPending();

        $this->logger->info(
            "[{$curatorList->getTitle()}] Syncing list attributes...",
            $logContext = ['throttle' => $this->throttle]
        );

        yield $this->throttle->async(fn () => $this->porter->importOne(new Import(
            new PutCuratorList($this->session, $this->curatorId, $curatorList)
        )));

        if ($newAppIds) {
            $this->logger->info("[{$curatorList->getTitle()}] Reordering apps...", $logContext);

            yield $this->throttle->async(fn () => $this->porter->importOne(new Import(
                new PatchCuratorListAppOrder(
                    $this->session,
                    $this->curatorId,
                    $curatorList->getListId(),
                    $newAppIds
                )
            )));
        }
    }

    private function waitForPending(): void
    {
        $this->logger->debug('Waiting for pending requests...');

        await($this->throttle->getPending());
    }
}
