<?php

declare(strict_types=1);

namespace Vigen\Installer;

use Composer\InstalledVersions;
use OutOfRangeException;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Helper\QuestionHelper;
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
        self::seedTerminalSize();
        self::avoidStty();

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
     * Symfony Console probes the terminal for its size on every run. On Windows
     * with an MSYS `stty` on PATH - Laragon and Git for Windows both ship one -
     * that probe can fail, and because a failed probe is never cached it re-runs
     * on every question, leaking cmd.exe's "The system cannot find the path
     * specified." into the output once per prompt.
     *
     * Seeding COLUMNS/LINES makes Terminal::getWidth()/getHeight() return early,
     * before any probe happens. POSIX terminals probe correctly, so this is
     * Windows-only. An existing value is never overridden - and an empty one
     * counts as missing, since Terminal reads an empty COLUMNS as width 0.
     */
    private static function seedTerminalSize(): void
    {
        if ('\\' !== DIRECTORY_SEPARATOR) {
            return;
        }

        if (! getenv('COLUMNS')) {
            putenv('COLUMNS=120');
        }

        if (! getenv('LINES')) {
            putenv('LINES=40');
        }
    }

    /**
     * Symfony gives every ChoiceQuestion an autocompleter, and the prompt path
     * for a question with an autocompleter shells out to `stty` to put the
     * terminal into raw mode. Those calls are unredirected, so whatever `stty`
     * prints goes straight to the user's console:
     *
     *     shell_exec('stty -g')
     *     shell_exec('stty -icanon -echo')
     *
     * On Windows that is a coin toss. `stty` is not a Windows program; when it
     * resolves at all it is usually an MSYS one - Laragon and Git for Windows
     * both ship it, and both put it on PATH - and driving a native console
     * through it is not something it can do. When it fails, the failure is
     * printed once per choice prompt, which is why a bare `vigen create` shows
     * noise after every multiple-choice question but never after the plain
     * project-name one: that question has no autocompleter, so Symfony
     * short-circuits at QuestionHelper's `null === $autocomplete ||` test
     * before any stty call is reached.
     *
     * disableStty() is Symfony's own switch for this. It makes that same test
     * fail fast, so the autocomplete path is never entered and the process
     * spawns nothing at all while asking questions. The cost is tab-completion
     * on Windows, where it never worked reliably anyway; answering a
     * ChoiceQuestion by typing its number still works exactly as before.
     * POSIX terminals drive stty correctly, so they keep autocomplete.
     */
    private static function avoidStty(): void
    {
        if ('\\' === DIRECTORY_SEPARATOR) {
            QuestionHelper::disableStty();
        }
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
