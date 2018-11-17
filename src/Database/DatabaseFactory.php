<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

final class DatabaseFactory
{
    public function create(string $path = 'steam.sqlite'): Connection
    {
        return DriverManager::getConnection(['url' => "sqlite:///$path"]);
    }
}
