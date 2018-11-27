<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator;

use ScriptFUSION\Steam250\Curator\Database\DatabaseFactory;
use ScriptFUSION\Steam250\Shared\Log\LoggerFactory;

final class ReviewSynchroniserFactory
{
    public function create(
        string $dbPath,
        int $curatorId,
        string $usernameOrCookie,
        string $password = null
    ): ReviewSynchroniser {
        $porter = (new PorterFactory)->create();

        return new ReviewSynchroniser(
            (new CuratorSessionFactory)->create($porter, $usernameOrCookie, $password),
            $curatorId,
            $porter,
            (new DatabaseFactory)->create($dbPath),
            (new LoggerFactory)->create('Curator', false)
        );
    }
}
