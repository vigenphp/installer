<?php

declare(strict_types=1);

namespace Vigen\Installer;

use Composer\InstalledVersions;
use OutOfRangeException;
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
    private const PACKAGE = 'vigenphp/installer';

    private const FALLBACK_VERSION = 'dev';

    private readonly ConsoleApplication $console;

    public function __construct()
    {
        $this->console = new ConsoleApplication('Vigen Installer', self::version());
        $this->console->add(new CreateCommand());

        // Deliberately no default command. Setting one (even with single-command
        // mode off) keeps Symfony's optional `command` argument on the
        // application definition, and `create`'s required `name` argument cannot
        // be added after it - InputDefinition throws. Without a default, a bare
        // `vigen` falls through to Symfony's `list`, which is the behaviour
        // `laravel` has.
    }

    public function run(array $argv): int
    {
        return $this->console->run();
    }

    /**
     * Reads the version Composer actually installed rather than hardcoding it,
     * so that tagging a release can never leave `vigen --version` reporting a
     * stale number. Falls back when the package is run straight from a checkout
     * with no Composer runtime data available.
     */
    private static function version(): string
    {
        if (! class_exists(InstalledVersions::class)) {
            return self::FALLBACK_VERSION;
        }

        try {
            return InstalledVersions::getPrettyVersion(self::PACKAGE) ?? self::FALLBACK_VERSION;
        } catch (OutOfRangeException) {
            return self::FALLBACK_VERSION;
        }
    }
}
