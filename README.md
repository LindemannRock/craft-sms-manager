![SMS Manager](docs/images/hero.webp)

# SMS Manager for Craft CMS

[![Latest Version](https://img.shields.io/packagist/v/lindemannrock/craft-sms-manager.svg)](https://packagist.org/packages/lindemannrock/craft-sms-manager)
[![Craft CMS](https://img.shields.io/badge/Craft%20CMS-5.0%2B-orange.svg)](https://craftcms.com/)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://php.net/)
[![Logging Library](https://img.shields.io/badge/Logging%20Library-5.0%2B-green.svg)](https://github.com/LindemannRock/craft-logging-library)
[![License](https://img.shields.io/packagist/l/lindemannrock/craft-sms-manager.svg)](LICENSE.md)

SMS gateway and management plugin for Craft CMS 5.x with multi-provider support, analytics, and delivery logs.

## License

This is a commercial plugin licensed under the [Craft License](https://craftcms.github.io/license/). It will be available on the [Craft Plugin Store](https://plugins.craftcms.com) soon. See [LICENSE.md](LICENSE.md) for details.

## ⚠️ Pre-Release

This plugin is in active development and not yet available on the Craft Plugin Store. Features and APIs may change before the initial public release.

## Features

- **Multi-provider support** — connect MPP-SMS (Kuwait), Twilio (global), or add your own gateway
- **Sender ID management** — register, enable, and default the names messages are sent from
- **Arabic and English** — correct encoding per message language (UCS-2 for Arabic)
- **Analytics** — sent/failed totals, success rate, language and encoding breakdown, per-provider, per–sender ID, and per–source plugin views, with site and date filtering
- **SMS logs** — full delivery history with provider responses and errors; export to CSV, JSON, or Excel
- **Test SMS** — send a one-off message from the Control Panel to verify a setup
- **Dashboard** — at-a-glance messaging activity and provider status
- **Craft dashboard widgets** — optional SMS activity and recent-message widgets for the Craft dashboard
- **PHP send API** — one entry point for sending, with source-plugin attribution
- **Granular permissions** — providers, sender IDs, analytics, logs, and settings
- **12-language translations** and structured logging via Logging Library

## Requirements

- Craft CMS 5.0+
- PHP 8.2+
- [Logging Library](https://github.com/LindemannRock/craft-logging-library) 5.0+ — optional, install in CP for log viewing

## Installation

### Via Composer

```bash
composer require lindemannrock/craft-sms-manager
```

```bash
php craft plugin/install sms-manager
```

### Using DDEV

```bash
ddev composer require lindemannrock/craft-sms-manager
```

```bash
ddev craft plugin/install sms-manager
```

## Documentation

Full documentation is available in the [docs](docs/) folder.

## Support

- **Issues**: [GitHub Issues](https://github.com/LindemannRock/craft-sms-manager/issues)
- **Email**: [support@lindemannrock.com](mailto:support@lindemannrock.com)

## License

This plugin is licensed under the [Craft License](https://craftcms.github.io/license/). See [LICENSE.md](LICENSE.md) for details.

---

Developed by [LindemannRock](https://lindemannrock.com)
