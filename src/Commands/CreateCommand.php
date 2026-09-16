<?php

declare(strict_types=1);

namespace Vigen\Installer\Commands;

use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Vigen\Project\EnvWriter;
use Vigen\Project\Scaffolder;
use Vigen\Providers\ProviderModels;

#[AsCommand(name: 'create', description: 'Create a new Vigen project.')]
class CreateCommand extends Command
{
    /**
     * Provider key => the label shown in the prompt. The keys are what ends up
     * in .env, so they are the values that matter; the labels are display only.
     */
    private const PROVIDERS = [
        'ollama' => 'Ollama (Offline)',
        'openai' => 'OpenAI',
        'claude' => 'Claude',
        'gemini' => 'Gemini',
    ];

    protected function configure(): void
    {
        $this->setAliases(['new']);
        $this->addArgument('name', InputArgument::OPTIONAL, 'Directory name for the new project');
        $this->addOption('interface', null, InputOption::VALUE_REQUIRED, 'cli or gui - skips that prompt');
        $this->addOption('provider', null, InputOption::VALUE_REQUIRED, 'ollama|openai|claude|gemini - skips that prompt');
        $this->addOption('model', null, InputOption::VALUE_REQUIRED, 'Model name - skips that prompt');
        $this->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'API key for the chosen provider - skips that prompt');
    }

    /**
     * Runs in both interactive and non-interactive mode, before either
     * interact() or execute(), so the banner is shown exactly once.
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $output->writeln('<info>VIGEN</info>');
        $output->writeln('Describe what you want. Vigen builds it.');
        $output->writeln('');
    }

    /**
     * Collects every answer and writes it back into $input, so that execute()
     * reads a single fully-populated source and nothing is written to disk
     * until all the questions have been answered. Symfony skips this method
     * entirely under --no-interaction; execute() supplies the defaults then.
     */
    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        $helper = $this->getHelper('question');

        // 1. Project name. Declared optional so that a bare `vigen create`
        // prompts for it, the way `laravel new` does - a required argument is
        // rejected by ArgvInput before any prompt could run.
        if ($input->getArgument('name') === null) {
            $question = new Question('What is the name of your project? ');
            $question->setValidator(static function (?string $answer): string {
                $answer = trim((string) $answer);
                if ($answer === '') {
                    throw new RuntimeException('The project name cannot be empty.');
                }

                return $answer;
            });

            $input->setArgument('name', $helper->ask($input, $output, $question));
        }

        // 2. Preferred chat interface.
        if ($input->getOption('interface') === null) {
            $input->setOption('interface', $helper->ask(
                $input,
                $output,
                new ChoiceQuestion('Choose chat preferred:', ['cli', 'gui'], 0)
            ));
        }

        // 3. Default AI provider.
        if ($input->getOption('provider') === null) {
            $label = $helper->ask($input, $output, new ChoiceQuestion(
                'Which default AI Provider:',
                array_values(self::PROVIDERS),
                0
            ));

            $input->setOption('provider', array_search($label, self::PROVIDERS, true) ?: 'ollama');
        }

        // 4. Model for the chosen provider.
        if ($input->getOption('model') === null) {
            $input->setOption('model', $helper->ask(
                $input,
                $output,
                new ChoiceQuestion('Which model to use:', ProviderModels::for($this->provider($input)), 0)
            ));
        }

        // 5. API key, but only for providers that need one. Ollama runs locally
        // and has no key, so it is never asked for.
        $apiKeyEnvVar = ProviderModels::apiKeyEnvVar($this->provider($input));
        if ($apiKeyEnvVar !== null && $input->getOption('api-key') === null) {
            $question = new Question(sprintf('Enter your %s API key: ', $this->providerLabel($this->provider($input))));
            $question->setHidden(true);

            $input->setOption('api-key', (string) $helper->ask($input, $output, $question));
        }
    }

    /**
     * Everything past this point is the install itself. All values are read
     * from $input first, so nothing below can leave a half-built project
     * behind because a later question was abandoned.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = trim((string) $input->getArgument('name'));

        // Reachable under --no-interaction, where interact() never ran.
        if ($name === '') {
            $output->writeln('<error>A project name is required.</error>');

            return Command::INVALID;
        }

        $interface = strtolower((string) ($input->getOption('interface') ?? 'cli'));
        $provider = $this->provider($input);
        $model = (string) ($input->getOption('model') ?? ProviderModels::for($provider)[0] ?? '');
        $apiKey = $input->getOption('api-key');
        $apiKeyEnvVar = ProviderModels::apiKeyEnvVar($provider);

        $projectRoot = rtrim(getcwd() ?: '.', '/') . '/' . $name;

        if (is_dir($projectRoot)) {
            $output->writeln("<error>Directory \"{$name}\" already exists.</error>");

            return Command::FAILURE;
        }

        $output->writeln('> Creating your Vigen project...');

        mkdir($projectRoot, 0755, true);

        $scaffolder = new Scaffolder($projectRoot);
        foreach ($scaffolder->scaffold() as $dir) {
            $output->writeln("✓ {$dir}/");
        }

        file_put_contents($projectRoot . '/composer.json', $this->projectComposerJson($name));
        $output->writeln('✓ composer.json');

        $envValues = [
            'VIGEN_AI_PROVIDER' => $provider,
            'VIGEN_AI_MODEL' => $model,
            'VIGEN_CHAT_INTERFACE' => $interface,
        ];
        if ($apiKeyEnvVar !== null && $apiKey !== null && $apiKey !== '') {
            $envValues[$apiKeyEnvVar] = $apiKey;
        }
        (new EnvWriter($projectRoot))->write($envValues);
        $output->writeln('✓ .env configured');

        copy($this->frameworkStubPath(), $projectRoot . '/vigen');
        chmod($projectRoot . '/vigen', 0755);
        $output->writeln('✓ vigen (your entry point)');

        // `vigen serve` runs the *application*; the chatbox is `vigen gui`.
        // The two were split, so pointing a GUI user at `serve` sends them to a
        // server with no generated code to serve yet.
        $readme = "# {$name}\n\n"
            . "A [Vigen](https://github.com/vigenphp/vigen) application.\n\n"
            . "## Setup\n\n"
            . "```bash\n"
            . "composer install\n"
            . ($interface === 'gui'
                ? "php vigen gui      # chat with the AI in a browser\n"
                : "php vigen chat \"Create a user management system\"\n")
            . "php vigen migrate  # create the tables your migrations describe\n"
            . "php vigen serve    # run your app at http://127.0.0.1:8808\n"
            . "```\n";

        file_put_contents($projectRoot . '/README.md', $readme);

        $output->writeln('');
        $output->writeln('<info>Vigen project ready.</info>');
        $output->writeln('');
        $output->writeln("  cd {$name}");
        $output->writeln('  composer install');
        $output->writeln($interface === 'gui' ? '  php vigen gui' : '  php vigen chat "Create a user management system"');
        $output->writeln('  php vigen migrate');
        $output->writeln('  php vigen serve');
        $output->writeln('');

        return Command::SUCCESS;
    }

    /**
     * The chosen provider, lowercased, defaulting to ollama.
     */
    private function provider(InputInterface $input): string
    {
        return strtolower((string) ($input->getOption('provider') ?? 'ollama'));
    }

    private function providerLabel(string $provider): string
    {
        return self::PROVIDERS[$provider] ?? $provider;
    }

    /**
     * Composer requires package names to be lowercase and match its own name
     * pattern, so a directory called "MyApp" or "my app" would otherwise produce
     * a composer.json that `composer install` refuses to read. The directory
     * keeps whatever the user typed; only the package name is normalised.
     */
    private function packageName(string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');

        return 'vigen-app/' . ($slug === '' ? 'app' : $slug);
    }

    private function projectComposerJson(string $name): string
    {
        $data = [
            'name' => $this->packageName($name),
            'description' => 'A Vigen application. Describe what you want. Vigen builds it.',
            'type' => 'project',
            'license' => 'MIT',
            'require' => [
                'php' => '^8.2',
                'vigenphp/vigen' => '^1.0',
            ],
            'config' => [
                'allow-plugins' => [
                    'vigenphp/vigen' => true,
                ],
            ],
            'minimum-stability' => 'stable',
            'prefer-stable' => true,
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * Locates vigenphp/vigen's published `stubs/vigen` file by resolving
     * where the Scaffolder class was loaded from - works whether vigen is
     * installed as a sibling dependency (composer global require) or as a
     * path repository during local development, without hardcoding a
     * vendor directory layout.
     */
    private function frameworkStubPath(): string
    {
        $scaffolderFile = (new ReflectionClass(Scaffolder::class))->getFileName();
        if ($scaffolderFile === false) {
            throw new RuntimeException('Unable to locate the vigenphp/vigen package on disk.');
        }

        // $scaffolderFile is .../vigenphp/vigen/src/Project/Scaffolder.php
        $packageRoot = dirname($scaffolderFile, 3);
        $stub = $packageRoot . '/stubs/vigen';

        if (! is_file($stub)) {
            throw new RuntimeException("Expected to find {$stub} - is vigenphp/vigen installed correctly?");
        }

        return $stub;
    }
}
