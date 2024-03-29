<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator\Import;

use Psr\Log\LoggerInterface;
use ScriptFUSION\Porter\Connector\Recoverable\RecoverableExceptionHandler;
use ScriptFUSION\Porter\Import\Import;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\CuratorReview;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\CuratorSession;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\PutCuratorReview;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\RecommendationState;
use ScriptFUSION\Steam250\Curator\Ranking;

final class PutCuratorReviewImport extends Import
{
    private const LANG = 'en';

    private RecoverableExceptionHandler $exceptionHandler;

    public function __construct(CuratorSession $session, int $curatorId, array $app)
    {
        $number = new \NumberFormatter(self::LANG, \NumberFormatter::DEFAULT_STYLE);
        $percent = new \NumberFormatter(self::LANG, \NumberFormatter::PERCENT);
        $ordinal = new \NumberFormatter(self::LANG, \NumberFormatter::ORDINAL);

        $ranking = Ranking::memberByKey($app['list_id']);

        parent::__construct(new PutCuratorReview(
            $session,
            $curatorId,
            (new CuratorReview(
                $app['app_id'] | 0,
                'Rated '
                    . (
                        $app['rank'] === '1'
                            ? 'number one'
                            : "{$ordinal->format($app['rank'])} best"
                    )
                    . " {$ranking->getRatingDescription()}, with "
                    . $percent->format($app['positive_reviews'] / $app['total_reviews'])
                    . " positive reviews from {$number->format($app['total_reviews'])} gamers!",
                RecommendationState::RECOMMENDED()
            ))->setUrl("https://steam250.com{$ranking->getUrlPath()}#app/$app[app_id]/" . rawurlencode($app['name']))
        ));

        $this->setRecoverableExceptionHandler(
            $this->exceptionHandler = new PutCuratorReviewExceptionHandler($app['app_id'])
        );
    }

    public function setLogger(LoggerInterface $logger): self
    {
        $this->exceptionHandler->setLogger($logger);

        return $this;
    }
}
