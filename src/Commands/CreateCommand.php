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
    protected function configure(): void
    {
        $this->setAliases(['new']);
        $this->addArgument('name', InputArgument::OPTIONAL, 'Directory name for the new project');
        $this->addOption('interface', null, InputOption::VALUE_REQUIRED, 'cli or gui - skips that prompt');
        $this->addOption('provider', null, InputOption::VALUE_REQUIRED, 'ollama|openai|claude|gemini - skips that prompt');
        $this->addOption('model', null, InputOption::VALUE_REQUIRED, 'Model name - skips that prompt');
        $this->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'API key for the chosen provider - skips that prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $helper = $this->getHelper('question');

        $output->writeln('<info>VIGEN</info>');
        $output->writeln('Describe what you want. Vigen builds it.');
        $output->writeln('');

        // 1. Project name. Declared optional so that a bare `vigen create`
        // prompts for it, the way `laravel new` does - a required argument is
        // rejected by ArgvInput before any prompt could run.
        $name = $input->getArgument('name');
        if ($name === null) {
            $nameQuestion = new Question('What is the name of your project? ');
            $nameQuestion->setValidator(static function (?string $answer): string {
                $answer = trim((string) $answer);
                if ($answer === '') {
                    throw new RuntimeException('The project name cannot be empty.');
                }

                return $answer;
            });
            $name = $helper->ask($input, $output, $nameQuestion);
            $input->setArgument('name', $name);
        }
        $name = trim((string) $name);

        // Reached when the name was neither given nor prompted for, e.g. under
        // --no-interaction with no argument.
        if ($name === '') {
            $output->writeln('<error>A project name is required.</error>');

            return Command::INVALID;
        }

        $projectRoot = rtrim(getcwd() ?: '.', '/') . '/' . $name;

        if (is_dir($projectRoot)) {
            $output->writeln("<error>Directory \"{$name}\" already exists.</error>");

            return Command::FAILURE;
        }

        // 2. Preferred chat interface.
        $interface = $input->getOption('interface');
        if ($interface === null) {
            $interface = $helper->ask($input, $output, new ChoiceQuestion('Choose chat preferred:', ['cli', 'gui'], 0));
        }
        $interface = strtolower($interface);

        // 3. Default AI provider.
        $providerLabels = [
            'ollama' => 'Ollama (Offline)',
            'openai' => 'OpenAI',
            'claude' => 'Claude',
            'gemini' => 'Gemini',
        ];
        $provider = $input->getOption('provider');
        if ($provider === null) {
            $label = $helper->ask($input, $output, new ChoiceQuestion(
                'Which default AI Provider:',
                array_values($providerLabels),
                0
            ));
            $provider = array_search($label, $providerLabels, true) ?: 'ollama';
        }
        $provider = strtolower($provider);
        $providerLabel = $providerLabels[$provider] ?? $provider;

        // 4. Model for the chosen provider.
        $model = $input->getOption('model');
        if ($model === null) {
            $models = ProviderModels::for($provider);
            $model = $helper->ask($input, $output, new ChoiceQuestion('Which model to use:', $models, 0));
        }

        // 5. API key, if required and not already supplied.
        $apiKey = $input->getOption('api-key');
        $apiKeyEnvVar = ProviderModels::apiKeyEnvVar($provider);
        if ($apiKeyEnvVar !== null && $apiKey === null) {
            $keyQuestion = new Question("Enter your {$providerLabel} API key: ");
            $keyQuestion->setHidden(true);
            $apiKey = (string) $helper->ask($input, $output, $keyQuestion);
        }

        $output->writeln('');
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

        file_put_contents($projectRoot . '/README.md', "# {$name}\n\nA [Vigen](https://github.com/vigenphp/vigen) application.\n\n## Setup\n\n```bash\ncomposer install\n" . ($interface === 'gui' ? 'php vigen serve' : 'php vigen chat "Create a user management system"') . "\n```\n");

        $output->writeln('');
        $output->writeln('<info>Vigen project ready.</info>');
        $output->writeln('');
        $output->writeln("  cd {$name}");
        $output->writeln('  composer install');
        $output->writeln($interface === 'gui' ? '  php vigen serve' : '  php vigen chat "Create a user management system"');
        $output->writeln('');

        return Command::SUCCESS;
    }

    private function projectComposerJson(string $name): string
    {
        $data = [
            'name' => "vigen-app/{$name}",
            'description' => 'A Vigen application. Describe what you want. Vigen builds it.',
            'type' => 'project',
            'license' => 'MIT',
            'require' => [
                'php' => '^8.2',
                'vigenphp/vigen' => '^0.1',
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
