# Requirements

## System requirements

| Requirement | Version |
|-------------|---------|
| [Craft CMS](https://craftcms.com/) | 5.0+ |
| [PHP](https://php.net/) | 8.2+ |

## Dependencies

Composer pulls these packages automatically. Craft plugin dependencies also need to be installed in the Control Panel.

| Package | Version | Purpose |
|---------|---------|---------|
| [lindemannrock/craft-plugin-base](https://github.com/LindemannRock/craft-plugin-base) | 5.0+ | Shared base plugin utilities (helpers, traits, layouts) |
| [lindemannrock/craft-logging-library](https://github.com/LindemannRock/craft-logging-library) | 5.0+ | Optional — install in CP for log viewing |

You also need an account with at least one supported SMS gateway ([MPP-SMS](../feature-tour/provider-mpp-sms.md) or [Twilio](../feature-tour/provider-twilio.md)) to actually send messages.
