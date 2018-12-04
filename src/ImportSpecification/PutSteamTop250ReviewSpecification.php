<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator\ImportSpecification;

use ScriptFUSION\Porter\Connector\Recoverable\RecoverableException;
use ScriptFUSION\Porter\Connector\Recoverable\StatelessRecoverableExceptionHandler;
use ScriptFUSION\Porter\Net\Http\HttpServerException;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\CuratorReview;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\CuratorSession;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\PutCuratorReview;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\RecommendationState;
use ScriptFUSION\Porter\Specification\AsyncImportSpecification;

final class PutSteamTop250ReviewSpecification extends AsyncImportSpecification
{
    private const LANG = 'en';

    public function __construct(CuratorSession $session, int $curatorId, array $app)
    {
        $number = new \NumberFormatter(self::LANG, \NumberFormatter::DEFAULT_STYLE);
        $percent = new \NumberFormatter(self::LANG, \NumberFormatter::PERCENT);
        $ordinal = new \NumberFormatter(self::LANG, \NumberFormatter::ORDINAL);

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
                    . ' Steam game of all time, with '
                    . $percent->format($app['positive_reviews'] / $app['total_reviews'])
                    . " positive reviews from {$number->format($app['total_reviews'])} gamers!",
                RecommendationState::RECOMMENDED()
            ))->setUrl("https://steam250.com/#app/$app[app_id]/" . rawurlencode($app['name']))
        ));

        $this->setRecoverableExceptionHandler(new StatelessRecoverableExceptionHandler(
            \Closure::fromCallable([$this, 'handleException'])
        ));
    }

    private function handleException(RecoverableException $exception): void
    {
        if ($exception instanceof HttpServerException && $exception->getCode() === 400) {
            $response = \json_decode($exception->getResponse()->getBody());
            if ($response->success === 8) {
                throw new \RuntimeException('Invalid param: app probably deleted.');
            }
        }
    }
}
