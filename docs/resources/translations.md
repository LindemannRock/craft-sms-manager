# Translations

SMS Manager includes full translations for 12 languages out of the box.

## Supported languages

| Language | Code |
|----------|------|
| English | `en` |
| German | `de` |
| French | `fr` |
| Dutch | `nl` |
| Spanish | `es` |
| Arabic | `ar` |
| Italian | `it` |
| Portuguese | `pt` |
| Japanese | `ja` |
| Swedish | `sv` |
| Danish | `da` |
| Norwegian | `no` |

Translations are automatically applied based on the user's preferred language in Craft's Control Panel settings.

## Language notes

- **Arabic**: Uses Modern Standard Arabic (MSA) with RTL support. Craft handles the RTL layout automatically.
- **Japanese**: Uses polite form (です/ます) with katakana for adopted technical terms.
- **All languages**: Acronyms and brand names (SMS, API, URL, MPP-SMS, Twilio, etc.) remain in Latin script as is standard in software localization.

## Overriding translations

You can override any translation string by creating a static translation file in your project:

```
translations/
└── de/
    └── sms-manager.php
```

```php
<?php

return [
    'Providers' => 'Anbieter',  // Override the default
];
```

Only the keys you include in your override file are replaced — all other strings use the plugin's built-in translations.

See [Craft's Static Translation Strings](https://craftcms.com/docs/5.x/system/sites.html#static-message-translations) for more details.

## Contributing translations

If you find a translation error or want to improve a translation, please [open an issue](https://github.com/LindemannRock/craft-sms-manager/issues) with:

- The language affected
- The current (incorrect) string
- Your suggested correction
