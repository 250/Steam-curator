<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator;

use ScriptFUSION\Steam250\Curator\Database\DatabaseFactory;
use ScriptFUSION\Steam250\Log\LoggerFactory;

final class ReviewSynchronizerFactory
{
    public function create(
        string $dbPath,
        int $curatorId,
        string $usernameOrCookie,
        string $password = null,
        bool $verbose = false
    ): ReviewSynchronizer {
        $porter = (new PorterFactory)->create();

        return new ReviewSynchronizer(
            (new CuratorSessionFactory)->create($porter, $usernameOrCookie, $password),
            $curatorId,
            $porter,
            (new DatabaseFactory)->create($dbPath),
            (new LoggerFactory)->create('Curator', $verbose)
        );
    }
}
