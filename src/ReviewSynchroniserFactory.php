<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator;

use Monolog\Logger;

final class ReviewSynchroniserFactory
{
    public function create(
        string $dbPath,
        string $curatorId,
        string $usernameOrCookie,
        string $password = null
    ): ReviewSynchroniser {
        $porter = (new PorterFactory)->create();

        return new ReviewSynchroniser(
            (new CuratorSessionFactory)->create($porter, $usernameOrCookie, $password),
            $curatorId,
            $porter,
            (new DatabaseFactory)->create($dbPath),
            new Logger('Curator')
        );
    }
}
