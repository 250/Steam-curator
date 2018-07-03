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
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\CuratorSession;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\PutCuratorReview;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\RecommendationState;
use ScriptFUSION\Porter\Specification\AsyncImportSpecification;

class ReviewSynchroniser
{
    private const LANG = 'en';

    private $session;

    private $curatorId;

    private $porter;

    private $database;

    private $logger;

    private $throttle;

    public function __construct(
        CuratorSession $session,
        string $curatorId,
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

        $apps = new Producer(function (\Closure $emit): \Generator {
            $number = new \NumberFormatter(self::LANG, \NumberFormatter::DEFAULT_STYLE);
            $percent = new \NumberFormatter(self::LANG, \NumberFormatter::PERCENT);
            $ordinal = new \NumberFormatter(self::LANG, \NumberFormatter::ORDINAL);

            $query = $this->database->executeQuery('
                SELECT rank.*, name, total_reviews, positive_reviews, total
                FROM rank, (SELECT COUNT(*) as total FROM rank WHERE list_id = \'index\')
                INNER JOIN app ON rank.app_id = app.id
                WHERE list_id = \'index\'
                ORDER BY rank DESC
            ');

            while ($app = $query->fetch()) {
                yield $this->throttle->await($emit(
                    \Amp\call(function () use ($app, $number, $percent, $ordinal): \Generator {
                        return [
                            yield $this->porter->importOneAsync(new AsyncImportSpecification(
                                new PutCuratorReview(
                                    $this->session,
                                    $this->curatorId,
                                    $app['app_id'],
                                    "$app[name] is the "
                                        . (
                                            $app['rank'] === '1'
                                                ? 'number one'
                                                : "{$ordinal->format($app['rank'])} best"
                                        )
                                        . ' Steam game of all time, with '
                                        . $percent->format($app['positive_reviews'] / $app['total_reviews'])
                                        . " positive reviews from {$number->format($app['total_reviews'])} gamers!",
                                    RecommendationState::RECOMMENDED(),
                                    "https://steam250.com/#app/$app[app_id]/" . rawurlencode($app['name'])
                                )
                            )),
                            $app,
                        ];
                    })
                ));
            }

            yield $this->throttle->finish();
        });

        $errors = false;

        Loop::run(function () use ($apps, &$errors): \Generator {
            $count = 0;
            $updated = [];

            while (yield $apps->advance()) {
                [$response, $app] = $apps->getCurrent();
                $percent = ++$count / $app['total'] * 100 | 0;

                if ($response['success'] === 1) {
                    $this->logger->info(
                        "$count/$app[total] ($percent%) Synced #$app[app_id] $app[name] OK.",
                        ['throttle' => $this->throttle]
                    );
                } else {
                    $this->logger->error("Failed to sync #$app[app_id] $app[name]! Error code: $response[success].");

                    $errors = true;
                }

                $updated[] = $app['app_id'];
            }

            yield $this->archiveObsoleteReviews($updated);
        });

        return !$errors;
    }

    private function archiveObsoleteReviews(array $freshAppIds): Promise
    {
        return \Amp\call(function () use ($freshAppIds) {
            $staleApps = $this->porter->importAsync(
                new ObsoleteReviewsSpecification($this->session, $this->curatorId, $freshAppIds)
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
                    (string)$stale['appid'],
                    "$stale[app_name] was a member of the Steam Top 250 until " . date('F jS, Y.'),
                    RecommendationState::INFORMATIONAL()
                )));
            } while (yield $staleApps->advance());
        });
    }
}
