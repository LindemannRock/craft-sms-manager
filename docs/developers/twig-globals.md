# Twig globals

Use SMS Manager's configured display name in your own Control Panel Twig without hard-coding it. The `smsHelper` global is a naming helper only; sending SMS is available through the [PHP API](sending-sms.md), not Twig.

## `smsHelper`

*Provided by `lindemannrock/base`*

| Property | Description |
|----------|-------------|
| `smsHelper.displayName` | Display name (singular, without "Manager") |
| `smsHelper.pluralDisplayName` | Plural display name (without "Manager") |
| `smsHelper.fullName` | Full plugin name (as configured) |
| `smsHelper.lowerDisplayName` | Lowercase display name (singular) |
| `smsHelper.pluralLowerDisplayName` | Lowercase plural display name |

### Choose the label you need

```twig
{{ smsHelper.displayName }}
{{ smsHelper.pluralDisplayName }}
{{ smsHelper.fullName }}
{{ smsHelper.lowerDisplayName }}
{{ smsHelper.pluralLowerDisplayName }}
```
