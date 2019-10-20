<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator\ImportSpecification;

use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\CuratorSession;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\GetCuratorReviews;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\RecommendationState;
use ScriptFUSION\Porter\Specification\AsyncImportSpecification;
use ScriptFUSION\Porter\Transform\FilterTransformer;

final class ListObsoleteReviewsSpecification extends AsyncImportSpecification
{
    public function __construct(CuratorSession $session, int $curatorId, array $freshAppIds)
    {
        parent::__construct(new GetCuratorReviews($session, $curatorId));

        $this->addTransformer(new FilterTransformer(static function (array $record) use ($freshAppIds): bool {
            return $record['recommendation']['recommendation_state'] === RecommendationState::RECOMMENDED()->toInt()
                && !\in_array($record['appid'], $freshAppIds, false);
        }));
    }
}
