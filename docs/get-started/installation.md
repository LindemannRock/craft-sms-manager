# Installation & Setup

> [!NOTE]
> Pre-Release: SMS Manager is in active development and not yet available on the Craft Plugin Store. Install via Composer for now.

## Composer

Add the package to your project using Composer and the command line.

1. Open your terminal and go to your Craft project:

```bash
cd /path/to/project
```

2. Then tell Composer to require the plugin, and Craft to install it:

```bash title="Composer"
composer require lindemannrock/craft-sms-manager && php craft plugin/install sms-manager
```

```bash title="DDEV"
ddev composer require lindemannrock/craft-sms-manager && ddev craft plugin/install sms-manager
```

3. **Optional** — Enable [Logging Library](https://github.com/LindemannRock/craft-logging-library) for log viewing:

> [!NOTE]
> Logging Library is included as a Composer dependency and downloaded automatically. Activate it in Craft to enable log viewing.

```bash title="PHP"
php craft plugin/install logging-library
```

```bash title="DDEV"
ddev craft plugin/install logging-library
```

Or via the Control Panel: **Settings → Plugins → Logging Library → Install**

## Post-Install Setup

After installing, connect a gateway so SMS Manager can send:

### 1. Add a Provider and Sender ID

Set up a provider under **SMS Manager → Providers**, add at least one sender ID under **SMS Manager → Sender IDs**, then choose your defaults in **SMS Manager → Settings → General**. See [Quickstart](quickstart.md) for the step-by-step.

### 2. Copy the Config File (Optional)

For advanced configuration — locking settings per environment, or declaring providers and sender IDs in code — copy the config file to your project:

```bash
cp vendor/lindemannrock/craft-sms-manager/src/config.php config/sms-manager.php
```

This gives you full control over plugin settings, providers, sender IDs, and outbound request security. See [Configuration](configuration.md) for details.

### 3. Review Configuration

See [Configuration](configuration.md) for all available settings. Most can be managed from **SMS Manager → Settings** without a config file.
