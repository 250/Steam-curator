<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator;

use Amp\Loop;
use Amp\Producer;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use ScriptFUSION\Async\Throttle\Throttle;
use ScriptFUSION\Porter\Porter;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\CuratorSession;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\PutCuratorReview;
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
        $this->throttle = new Throttle;
    }

    public function synchronize(): bool
    {
        $this->logger->info('Beginning review synchronization...');

        $reviews = new Producer(function (\Closure $emit): \Generator {
            $number = new \NumberFormatter(self::LANG, \NumberFormatter::DEFAULT_STYLE);
            $percent = new \NumberFormatter(self::LANG, \NumberFormatter::PERCENT);
            $ordinal = new \NumberFormatter(self::LANG, \NumberFormatter::ORDINAL);

            $query = $this->database->executeQuery('
                SELECT rank.*, app.name, app.total_reviews, app.positive_reviews, total
                FROM rank, (SELECT COUNT(*) as total FROM rank WHERE list_id = \'index\')
                INNER JOIN app ON rank.app_id = app.id
                WHERE list_id = \'index\'
                ORDER BY rank DESC
            ');

            while ($app = $query->fetch()) {
                yield $this->throttle->await($emit(
                    \Amp\call(function () use ($app, $number, $percent, $ordinal) {
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
                                    "https://steam250.com/#app/$app[app_id]/" . rawurlencode($app['name'])
                                )
                            )),
                            $app
                        ];
                    })
                ));
            }

            yield $this->throttle->finish();
        });

        $errors = false;

        Loop::run(function () use ($reviews, &$errors): \Generator {
            $count = 0;

            while (yield $reviews->advance()) {
                [$response, $app] = $reviews->getCurrent();
                $percent = ++$count / $app['total'] * 100 | 0;

                if ($response['success'] === 1) {
                    $this->logger->info("$count/$app[total] ($percent%) Synced #$app[app_id] $app[name] OK.");
                } else {
                    $this->logger->error("Failed to sync #$app[app_id] $app[name]! Error code: $response[success].");

                    $errors = true;
                }
            }
        });

        return !$errors;
    }
}
