<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator;

use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\CuratorSession;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\ListCuratorReviews;
use ScriptFUSION\Porter\Specification\AsyncImportSpecification;
use ScriptFUSION\Porter\Transform\FilterTransformer;

final class ObsoleteReviewsSpecification extends AsyncImportSpecification
{
    public function __construct(CuratorSession $session, string $curatorId, array $freshAppIds)
    {
        parent::__construct(new ListCuratorReviews($session, $curatorId));

        $this->addTransformer(new FilterTransformer(function (array $record) use ($freshAppIds) {
            return $record['recommendation']['recommendation_state'] === 0
                && !\in_array($record['appid'], $freshAppIds, false);
        }));
    }
}
