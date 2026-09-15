<?php

declare(strict_types=1);

namespace Vigen\Installer;

use Symfony\Component\Console\Application as ConsoleApplication;
use Vigen\Installer\Commands\CreateCommand;

/**
 * The global installer. Deliberately minimal - its only job is `vigen
 * create <name>`. Once a project exists, all further work (`chat`,
 * `serve`) happens through the project-local `./vigen` script instead,
 * exactly as Laravel splits the global `laravel` binary (project creation
 * only) from the per-project `artisan` (everything else).
 */
class Application
{
    private readonly ConsoleApplication $console;

    public function __construct()
    {
        $this->console = new ConsoleApplication('Vigen Installer', '0.1.0');
        $this->console->add(new CreateCommand());
        $this->console->setDefaultCommand('create', false);
    }

    public function run(array $argv): int
    {
        return $this->console->run();
    }
}
