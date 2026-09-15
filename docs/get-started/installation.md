# Installation & Setup

> [!NOTE]
> SMS Manager is in active development and not yet available on the Craft Plugin Store. Install via Composer for now.

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
> Logging Library is required by Composer. Install or activate it in Craft to enable log viewing.

```bash title="PHP"
php craft plugin/install logging-library
```

```bash title="DDEV"
ddev craft plugin/install logging-library
```

Or via the Control Panel: **Settings → Plugins → Logging Library → Install**

See [Logging](../resources/logging.md) for log levels, files, and viewer permissions.

## Post-Install Setup

After installing, connect a gateway so SMS Manager can send.

### Add a provider and sender ID

Set up a provider under **SMS Manager → Providers**, add at least one sender ID under **SMS Manager → Sender IDs**, then choose your defaults in **SMS Manager → Settings → General**. See [Quickstart](quickstart.md) for the step-by-step.

### Review configuration

See [Configuration](configuration.md) for all available settings. Most can be managed from **SMS Manager → Settings** without a config file.

## Quick Start

See [Quickstart](quickstart.md) for the fastest path from install to first result.
