# Vigen Installer

The global Vigen installer - `vigen create my-app`, the same way `laravel/installer` gives you `laravel new`.

## Install

```bash
composer global require vigenphp/installer
```

Make sure Composer's global `vendor/bin` directory is on your `PATH` (usually `~/.composer/vendor/bin` or `~/.config/composer/vendor/bin`):

```bash
export PATH="$HOME/.config/composer/vendor/bin:$PATH"
```

## Use

```bash
vigen create my-app
```

Answer three questions - interface (CLI/GUI), default AI provider, and model - and Vigen scaffolds a complete project: folder structure, `composer.json`, `.env`, and the project's own `vigen` entry point.

```bash
cd my-app
composer install
php vigen serve   # if you chose GUI
php vigen chat "Create a user management system"   # if you chose CLI
```

The global `vigen` command only creates projects. Once inside one, all further work goes through the project's own `./vigen` script - exactly like Laravel splits the global `laravel` binary from the per-project `artisan`.

## Non-interactive use

For scripting or CI:

```bash
vigen create my-app --interface=cli --provider=ollama --model=qwen2.5-coder:14b
```
