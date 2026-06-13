# Twig Globals

SMS Manager provides the following global variables in your Twig templates.

## `smsHelper`

*Provided by `lindemannrock/base`*

| Property | Description |
|----------|-------------|
| `smsHelper.displayName` | Display name (singular, without "Manager") |
| `smsHelper.pluralDisplayName` | Plural display name (without "Manager") |
| `smsHelper.fullName` | Full plugin name (as configured) |
| `smsHelper.lowerDisplayName` | Lowercase display name (singular) |
| `smsHelper.pluralLowerDisplayName` | Lowercase plural display name |

### Examples

```twig
{{ smsHelper.displayName }}
{{ smsHelper.pluralDisplayName }}
{{ smsHelper.fullName }}
{{ smsHelper.lowerDisplayName }}
{{ smsHelper.pluralLowerDisplayName }}
```

---

