<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator;

use Amp\Loop;
use Amp\Producer;
use Amp\Promise;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use ScriptFUSION\Async\Throttle\Throttle;
use ScriptFUSION\Porter\Porter;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\CuratorReview;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\CuratorSession;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\PutCuratorReview;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\RecommendationState;
use ScriptFUSION\Porter\Specification\AsyncImportSpecification;
use ScriptFUSION\Steam250\Curator\ImportSpecification\ListObsoleteReviewsSpecification;
use ScriptFUSION\Steam250\Curator\ImportSpecification\PutSteamTop250ReviewSpecification;

final class ReviewSynchroniser
{
    private $session;

    private $curatorId;

    private $porter;

    private $database;

    private $logger;

    private $throttle;

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
        $this->throttle = new Throttle(6, 6);
    }

    public function synchronize(): bool
    {
        $this->logger->info('Beginning review synchronization...');

        /** @var SyncReviewsStatus $syncReviewsStatus */
        $syncReviewsStatus = null;

        Loop::run(function () use (&$syncReviewsStatus): \Generator {
            /** @var SyncReviewsStatus $syncReviewsStatus */
            $syncReviewsStatus = yield $this->synchronizeReviews();

            $this->logger->info(
                'Summary: Updated: ' . count($syncReviewsStatus->getSucceeded())
                    . ', Skipped: ' . count($syncReviewsStatus->getSkipped())
                    . ', Errors: ' . count($syncReviewsStatus->getErrors())
                    . '.'
            );

            yield $this->archiveObsoleteReviews($syncReviewsStatus->getSucceeded());
        });

        return count($syncReviewsStatus->getErrors()) === 0;
    }

    private function synchronizeReviews(): Promise
    {
        return \Amp\call(function (): \Generator {
            $reviews = $this->putReviews();
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
                    'throttle' => $this->throttle,
                ];

                if (!isset($response['success'])) {
                    $this->logger->warning("[$app[list_id]] Skipped %app%: \"$response\"", $logContext);

                    $skipped[] = $app['app_id'];
                } elseif ($response['success'] === 1) {
                    $this->logger->info("[$app[list_id]] Synced %app% OK.", $logContext);

                    $updated[] = $app['app_id'];
                } else {
                    $this->logger->error(
                        "[$app[list_id]] Failed to sync %app%! Error code: $response[success].",
                        $logContext
                    );

                    $errors[] = $app['app_id'];
                }
            }

            return new SyncReviewsStatus($updated, $skipped, $errors);
        });
    }

    private function putReviews(): CountableIterator
    {
        $apps = $this->fetchReviewApps();

        return new CountableIterator(
            \count($apps),
            new Producer(function (\Closure $emit) use ($apps): \Generator {
                foreach ($apps as $app) {
                    yield $this->throttle->await($emit(
                        \Amp\call(function () use ($app): \Generator {
                            try {
                                return [
                                    yield $this->porter->importOneAsync(
                                        new PutSteamTop250ReviewSpecification($this->session, $this->curatorId, $app)
                                    ),
                                    $app,
                                ];
                            } catch (\Exception $exception) {
                                return [$exception->getMessage(), $app];
                            }
                        })
                    ));
                }

                yield $this->throttle->finish();
            })
        );
    }

    /**
     * Fetches a collection of apps that can be pushed as curator reviews.
     *
     * Duplicate apps are filtered, leaving only the app whose ranking has the highest priority
     * (determined by smallest priority value).
     *
     * @return iterable Collection of apps.
     */
    private function fetchReviewApps(): iterable
    {
        return $this->database->fetchAll('
            WITH p(id, priority) AS (VALUES
                (\'index\', 0),
                (\'hidden_gems\', 1)
            )
            SELECT rank.*, app.id, name, total_reviews, positive_reviews, MIN(priority)
            FROM rank
            INNER JOIN app ON rank.app_id = app.id
            INNER JOIN p ON p.id = list_id
            GROUP BY app_id
            ORDER BY priority, rank DESC
        ');
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

                $this->logger->info("Archived obsolete review: $stale[app_name].");

                yield $this->porter->importOneAsync(new AsyncImportSpecification(new PutCuratorReview(
                    $this->session,
                    $this->curatorId,
                    new CuratorReview(
                        $stale['appid'],
                        "$stale[app_name] was a member of the Steam Top 250 until " . date('F jS, Y.'),
                        RecommendationState::INFORMATIONAL()
                    )
                )));
            } while (yield $staleApps->advance());
        });
    }
}
