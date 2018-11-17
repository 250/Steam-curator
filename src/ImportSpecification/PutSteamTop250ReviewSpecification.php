<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator\ImportSpecification;

use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\CuratorSession;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\PutCuratorReview;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\RecommendationState;
use ScriptFUSION\Porter\Specification\AsyncImportSpecification;

final class PutSteamTop250ReviewSpecification extends AsyncImportSpecification
{
    private const LANG = 'en';

    public function __construct(CuratorSession $session, string $curatorId, array $app)
    {
        $number = new \NumberFormatter(self::LANG, \NumberFormatter::DEFAULT_STYLE);
        $percent = new \NumberFormatter(self::LANG, \NumberFormatter::PERCENT);
        $ordinal = new \NumberFormatter(self::LANG, \NumberFormatter::ORDINAL);

        parent::__construct(new PutCuratorReview(
            $session,
            $curatorId,
            $app['app_id'],
            'Rated '
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
        ));
    }
}
