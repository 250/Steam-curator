<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator;

use Amp\Iterator;
use Amp\Loop;
use Amp\Producer;
use Amp\Promise;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use ScriptFUSION\Async\Throttle\Throttle;
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
use ScriptFUSION\Porter\Specification\AsyncImportSpecification;
use ScriptFUSION\Steam250\Curator\ImportSpecification\GetCuratorListsSpecification;
use ScriptFUSION\Steam250\Curator\ImportSpecification\ListObsoleteReviewsSpecification;
use ScriptFUSION\Steam250\Curator\ImportSpecification\PutCuratorReviewSpecification;

final class ReviewSynchroniser
{
    private const MAX_LIST_LENGTH = 100;

    private $session;

    private $curatorId;

    private $porter;

    private $database;

    private $logger;

    public function __construct(
        CuratorSession $session,
        int $curatorId,
        Porter $porter,
        Connection $database,
        LoggerInterface $logger
    ) {
        $this->session = $session;
        $this->curatorId = $curatorId;
        $this->porter = $porter;
        $this->database = $database;
        $this->logger = $logger;
    }

    public function synchronize(): bool
    {
        $this->logger->info('Beginning review synchronization...');

        /** @var SynchronizeReviewsResult $syncReviewsStatus */
        $syncReviewsStatus = null;

        Loop::run(function () use (&$syncReviewsStatus): \Generator {
            $lists = yield $this->fetchLists();

            /** @var SynchronizeReviewsResult $syncReviewsStatus */
            $syncReviewsStatus = yield $this->synchronizeReviews();

            $this->logger->info(
                'Summary: Updated: ' . count($syncReviewsStatus->getSucceeded())
                    . ', Skipped: ' . count($syncReviewsStatus->getSkipped())
                    . ', Errors: ' . count($syncReviewsStatus->getErrors())
                    . '.'
            );

            yield $this->archiveObsoleteReviews(array_column($syncReviewsStatus->getSucceeded(), 'id'));

            yield $this->synchronizeLists($lists, $syncReviewsStatus);
        });

        return count($syncReviewsStatus->getErrors()) === 0;
    }

    private function fetchLists(): Promise
    {
        return \Amp\call(function (): \Generator {
            $listCollection = $this->porter->importAsync(
                new GetCuratorListsSpecification($this->session, $this->curatorId)
            );

            $lists = [];
            while (yield $listCollection->advance()) {
                $lists[$listCollection->getCurrent()['title']] = $listCollection->getCurrent();
            }

            self::validateLists($lists);

            return $lists;
        });
    }

    private static function validateLists(array $lists): void
    {
        foreach (Ranking::members() as $ranking) {
            if (!isset($lists[$ranking->getCanonicalName()])) {
                throw new \RuntimeException("Required ranking not found: \"{$ranking->getCanonicalName()}\".");
            }
        }
    }

    private function synchronizeReviews(): Promise
    {
        return \Amp\call(function (): \Generator {
            $reviews = $this->putReviews($throttle = self::createThrottle());
            $count = 0;
            $updated = [];
            $skipped = [];
            $errors = [];

            while (yield $reviews->advance()) {
                [$response, $app] = $reviews->getCurrent();

                $logContext = [
                    'app' => $app,
                    'count' => ++$count,
                    'total' => \count($reviews),
                    'throttle' => $throttle,
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
        });
    }

    private function putReviews(Throttle $throttle): CountableIterator
    {
        $apps = $this->fetchAllApps();

        return new CountableIterator(
            \count($apps),
            new Producer(function (\Closure $emit) use ($throttle, $apps): \Generator {
                foreach ($apps as $app) {
                    yield $throttle->await($emit(
                        \Amp\call(function () use ($app): \Generator {
                            try {
                                return [
                                    yield $this->porter->importOneAsync(
                                        new PutCuratorReviewSpecification($this->session, $this->curatorId, $app)
                                    ),
                                    $app,
                                ];
                            } catch (\Exception $exception) {
                                return [$exception->getMessage(), $app];
                            }
                        })
                    ));
                }

                yield $throttle->finish();
            })
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

        return $this->database->fetchAll("
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
        return from(Ranking::members())
            ->select(static function (Ranking $ranking): string {
                return "('{$ranking}', {$ranking->getPriority()})";
            })
            ->toString(',');
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
        return from(
            $this->database->fetchAll("
                SELECT app_id
                FROM rank
                WHERE list_id = '$rankingName'
                ORDER BY rank
            ")
        )->select('$v["app_id"]')->cast('int')->toArray();
    }

    private function archiveObsoleteReviews(array $freshAppIds): Promise
    {
        return \Amp\call(function () use ($freshAppIds) {
            $staleApps = $this->porter->importAsync(
                new ListObsoleteReviewsSpecification($this->session, $this->curatorId, $freshAppIds)
            );

            if (!yield $staleApps->advance()) {
                $this->logger->info('No obsolete reviews found.');

                return;
            }

            do {
                $stale = $staleApps->getCurrent();

                $this->logger->info("Archiving obsolete review: #$stale[appid] $stale[app_name]...");

                $ranking = Ranking::fromUrl($stale['recommendation']['link_url']);

                yield $this->porter->importOneAsync(new AsyncImportSpecification(new PutCuratorReview(
                    $this->session,
                    $this->curatorId,
                    new CuratorReview(
                        $stale['appid'],
                        "$stale[app_name] was a member of the {$ranking->getCanonicalName()} until " . date('F jS, Y.'),
                        RecommendationState::INFORMATIONAL()
                    )
                )));
            } while (yield $staleApps->advance());
        });
    }

    private function synchronizeLists(array $lists, SynchronizeReviewsResult $syncReviewsStatus): Promise
    {
        return \Amp\call(function () use ($lists, $syncReviewsStatus): \Generator {
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

                while (yield $operations->advance()) {
                    $response = $operations->getCurrent();

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
        });
    }

    private function reconcileListApps(CuratorList $curatorList, array $newAppIds): Iterator
    {
        // Clamp list length to max length.
        array_splice($newAppIds, self::MAX_LIST_LENGTH);

        $add = array_diff($newAppIds, $curatorList->getAppIds());
        $remove = array_diff($curatorList->getAppIds(), $newAppIds);

        return new Producer(function (\Closure $emit) use ($add, $remove, $curatorList, $newAppIds): \Generator {
            $throttle = self::createThrottle();

            $count = 0;
            $total = count($remove);
            $logContext = ['throttle' => $throttle, 'count' => &$count, 'total' => &$total];

            // Push removals. Removals must be pushed before adds to avoid overflowing the list length limit.
            foreach ($remove as $appId) {
                ++$count;

                $this->logger->info("[{$curatorList->getTitle()}] Removing: #$appId...", $logContext);

                yield $throttle->await($emit($this->porter->importOneAsync(new AsyncImportSpecification(
                    new DeleteCuratorListApp($this->session, $this->curatorId, $curatorList->getListId(), $appId)
                ))));
            }

            // TODO: Probably a bug. Removals must complete before any additions are made.

            $count = 0;
            $total = count($add);
            // Push additions.
            foreach ($add as $appId) {
                ++$count;

                $this->logger->info("[{$curatorList->getTitle()}] Adding: #$appId...", $logContext);

                yield $throttle->await($emit($this->porter->importOneAsync(new AsyncImportSpecification(
                    new PutCuratorListApp($this->session, $this->curatorId, $curatorList->getListId(), $appId)
                ))));
            }

            yield $throttle->finish();

            $this->logger->info("[{$curatorList->getTitle()}] Syncing list attributes...", compact('throttle'));

            yield $emit($this->porter->importOneAsync(new AsyncImportSpecification(
                new PutCuratorList($this->session, $this->curatorId, $curatorList)
            )));

            if ($newAppIds) {
                $this->logger->info("[{$curatorList->getTitle()}] Reordering apps...", compact('throttle'));

                yield $emit($this->porter->importOneAsync(new AsyncImportSpecification(
                    new PatchCuratorListAppOrder(
                        $this->session,
                        $this->curatorId,
                        $curatorList->getListId(),
                        $newAppIds
                    )
                )));
            }
        });
    }

    private static function createThrottle(): Throttle
    {
        return new Throttle(6, 6);
    }
}
