<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator;

use ScriptFUSION\Porter\Porter;
use ScriptFUSION\Porter\Provider\Steam\Cookie\SecureLoginCookie;
use ScriptFUSION\Porter\Provider\Steam\Resource\Curator\CuratorSession;

final class CuratorSessionFactory
{
    public function create(Porter $porter, string $usernameOrCookie, string $password = null): CuratorSession
    {
        if ($password === null) {
            return CuratorSession::createFromCookie(SecureLoginCookie::create($usernameOrCookie), $porter);
        }

        return CuratorSession::create($porter, $usernameOrCookie, $password);
    }
}
