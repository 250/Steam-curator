<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator;

final class Application
{
    private $app;

    public function __construct()
    {
        $this->app = $app = new \Symfony\Component\Console\Application;

        $app->addCommands([
            new SyncCommand,
        ]);
    }

    public function start(): int
    {
        return $this->app->run();
    }
}
