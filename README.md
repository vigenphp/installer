![Vigen Installer - create your Vigen project in seconds with vigen create my-app](vigen-installer-banner.png)

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

On Windows that directory is `%APPDATA%\Composer\vendor\bin`, which you add through **Edit the system environment variables** → **Environment Variables** → `Path`.

## Use

```bash
vigen create my-app
```

Answer three questions - interface (CLI/GUI), default AI provider, and model - and Vigen scaffolds a complete project: folder structure, `composer.json`, `.env`, and the project's own `vigen` entry point.

```bash
cd my-app
composer install
php vigen chat "Create a user management system"   # if you chose CLI
php vigen gui                                      # if you chose GUI
php vigen migrate                                  # create the tables your migrations describe
php vigen serve                                    # run your app at http://127.0.0.1:8808
```

`chat` and `gui` are two front ends onto the same generator - use whichever you picked. `gui` opens the chatbox in a browser; `chat` takes the prompt as an argument. `serve` is a different thing entirely: it runs *your* application, so there is nothing to serve until you have generated some code.

The global `vigen` command only creates projects. Once inside one, all further work goes through the project's own `./vigen` script - exactly like Laravel splits the global `laravel` binary from the per-project `artisan`.

## Non-interactive use

For scripting or CI:

```bash
vigen create my-app --interface=cli --provider=ollama --model=qwen2.5-coder:14b
```
