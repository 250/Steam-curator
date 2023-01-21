<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator\Import;

use ScriptFUSION\Mapper\AnonymousMapping;
use ScriptFUSION\Mapper\Strategy\Collection;
use ScriptFUSION\Mapper\Strategy\Copy;
use ScriptFUSION\Mapper\Strategy\CopyContext;
use ScriptFUSION\Porter\Import\Import;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\CuratorList\GetCuratorLists;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\CuratorSession;
use ScriptFUSION\Porter\Transform\Mapping\MappingTransformer;

final class GetCuratorListsImport extends Import
{
    public function __construct(CuratorSession $session, int $curatorId)
    {
        parent::__construct(new GetCuratorLists($session, $curatorId));

        $this->addTransformer(new MappingTransformer(new AnonymousMapping([
            'id' => new Copy('listid'),
            'title' => new Copy('title'),
            'appids' => new Collection(
                new Copy('apps'),
                new CopyContext('recommended_app->appid')
            ),
        ])));
    }
}
