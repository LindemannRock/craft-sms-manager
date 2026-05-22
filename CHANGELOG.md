# Changelog

## [5.12.0](https://github.com/LindemannRock/craft-sms-manager/compare/v5.11.0...v5.12.0) (2026-05-22)


### Added

* add pre-commit hook for ECS and PHPStan code quality checks ([6f38beb](https://github.com/LindemannRock/craft-sms-manager/commit/6f38beb0f0f7fb50afb943c37b4600514965e1fb))
* **analytics:** enhance analytics and logs cleanup scheduling ([dcd5a06](https://github.com/LindemannRock/craft-sms-manager/commit/dcd5a06487d2c36eb20e469c80a94a2d6b147fa3))
* **dashboard:** add Sender ID column to recent SMS logs table ([ab87c08](https://github.com/LindemannRock/craft-sms-manager/commit/ab87c081cdeff9a3571ca96bd045a6ce517bf05a))
* **dashboard:** add sender ID handle fallback for log attribution ([b56124f](https://github.com/LindemannRock/craft-sms-manager/commit/b56124f8888c57b23201ac746f8bb95b3fd98704))
* **i18n:** add 'Dev' translation key across multiple languages ([9048220](https://github.com/LindemannRock/craft-sms-manager/commit/90482201e741e9ddcd3888461647cf93f292312e))
* **i18n:** add default provider and sender ID translations in multiple languages ([1f6ef86](https://github.com/LindemannRock/craft-sms-manager/commit/1f6ef8680e3b20e9734abb26b60c6a5ea8c3ab90))
* **i18n:** add new SMS provider and sender ID messages in multiple languages ([fa103d7](https://github.com/LindemannRock/craft-sms-manager/commit/fa103d7c0cf11ca1791c641b82c1038cf6f9860f))
* **i18n:** add new SMS status messages and validation errors in multiple languages ([8955e79](https://github.com/LindemannRock/craft-sms-manager/commit/8955e79933effe56ced7ce7e0c48da9c6a26a567))
* **i18n:** add translation issue template for reporting language problems ([19f0b43](https://github.com/LindemannRock/craft-sms-manager/commit/19f0b43647ffc1cbc7658d1b0506b96a1cfb104b))
* **i18n:** register translation for country-not-allowed error message ([8a6968d](https://github.com/LindemannRock/craft-sms-manager/commit/8a6968d53a947ea9c224628a166f0f9f70d72365))
* **logs:** add handle snapshots for provider and sender ID resolution ([1fca14d](https://github.com/LindemannRock/craft-sms-manager/commit/1fca14d1f0276a93d61dcfe0f4351156f863ec71))
* **migrations:** add new configuration options for time and export formats ([7f8cfab](https://github.com/LindemannRock/craft-sms-manager/commit/7f8cfab06f5a207c3ff7ea9cec3a61adb50b7c77))
* **providers:** enhance provider handling with O(1) lookup and JSON acceptance ([73e15b9](https://github.com/LindemannRock/craft-sms-manager/commit/73e15b9b2a4aeeacf9b022689af961d19602c049))
* **tests:** add integration tests for SMS service functionality ([75cab20](https://github.com/LindemannRock/craft-sms-manager/commit/75cab207873d301054ecbae2252faead89a21e8d))


### Fixed

* **i18n:** add translation for sender ID and provider messages in settings ([16b9352](https://github.com/LindemannRock/craft-sms-manager/commit/16b9352c919bbc592770a10f45b404f03fe5f46d))
* **i18n:** correct provider update messages for clarity ([1c04d66](https://github.com/LindemannRock/craft-sms-manager/commit/1c04d660d85e2b9d5c272fe4d591dd52cede3d7d))
* **i18n:** correct sender ID update messages for clarity ([026753e](https://github.com/LindemannRock/craft-sms-manager/commit/026753eb89b9f5c8a11aecf32975e8d843446080))
* **i18n:** remove deprecated plugin name and logging settings translations ([e279d64](https://github.com/LindemannRock/craft-sms-manager/commit/e279d64c0ff9e25fc5a5ac39dce187df829cffd1))
* **i18n:** update default and dev labels for provider and sender ID options ([1a0adec](https://github.com/LindemannRock/craft-sms-manager/commit/1a0adec9ee874b93d9f7d2fe42206f38fad4c96b))
* **i18n:** update sender ID option text to use translation keys ([9fc40d1](https://github.com/LindemannRock/craft-sms-manager/commit/9fc40d11514a2b08bce2951add5494f8f9fee024))
* **settings:** handle empty multi-state select values correctly ([50b795f](https://github.com/LindemannRock/craft-sms-manager/commit/50b795fba7865b53a55749f22ed40f6a7694fb62))

## [5.11.0](https://github.com/LindemannRock/craft-sms-manager/compare/v5.10.2...v5.11.0) (2026-05-06)


### Features

* add issue templates for bug reports, feature requests, and questions ([7242c5f](https://github.com/LindemannRock/craft-sms-manager/commit/7242c5f49b1c8ee682d8e6d4b76fa60bd578e1f8))


### Bug Fixes

* apply config overrides through shared settings helper ([072a530](https://github.com/LindemannRock/craft-sms-manager/commit/072a530bb5b31c046176765ab48bed388246a5af))
* clarify plugin name comment in Settings model ([1e55b06](https://github.com/LindemannRock/craft-sms-manager/commit/1e55b068e4ff934fbe8c916544867b9649524327))
* drop PAT requirement for release-please — use built-in GITHUB_TOKEN ([a85f313](https://github.com/LindemannRock/craft-sms-manager/commit/a85f3133124b7c06420921327383f99de2328c68))
* **translations:** correct plugin name translations in multiple languages ([8087b60](https://github.com/LindemannRock/craft-sms-manager/commit/8087b60bfe8bc7c02bb5346d357e055ca0d265f7))


### Miscellaneous Chores

* update version annotations to reflect correct plugin versions ([0a1c738](https://github.com/LindemannRock/craft-sms-manager/commit/0a1c738795e87dc91666573c266a0b1246d4c68b))

## [5.10.2](https://github.com/LindemannRock/craft-sms-manager/compare/v5.10.1...v5.10.2) (2026-04-05)


### Bug Fixes

* **SmsManager:** read-only settings response and clarify property usage ([08d8f4f](https://github.com/LindemannRock/craft-sms-manager/commit/08d8f4fe24d8864e6faf97e54eae3d5e6d7f2847))

## [5.10.1](https://github.com/LindemannRock/craft-sms-manager/compare/v5.10.0...v5.10.1) (2026-04-02)


### Bug Fixes

* **svg:** update icon SVG files for improved styling and structure ([90cd7bf](https://github.com/LindemannRock/craft-sms-manager/commit/90cd7bf5596e22baf0212608136f2dbc99c6755c))
* update install experience text to use Craft translations ([b4faece](https://github.com/LindemannRock/craft-sms-manager/commit/b4faece71745ce2f629bae87aa8961bc5268ade2))

## [5.10.0](https://github.com/LindemannRock/craft-sms-manager/compare/v5.9.6...v5.10.0) (2026-03-17)


### Features

* **SmsManager:** add install experience configuration ([ff40c0f](https://github.com/LindemannRock/craft-sms-manager/commit/ff40c0fde9c13522e2a05249845c72289f5e48a9))


### Bug Fixes

* **SmsManagerUtility:** update icon path to new SVG file ([110ad05](https://github.com/LindemannRock/craft-sms-manager/commit/110ad0572d051468320b94fdd1eadf7233d56ade))
* **templates:** remove redundant submit buttons from settings forms ([3ca7cfe](https://github.com/LindemannRock/craft-sms-manager/commit/3ca7cfedf0c2782d1b64e13a2dac8abe2f9df9ba))


### Miscellaneous Chores

* **assets:** update asset management and build configuration ([6976568](https://github.com/LindemannRock/craft-sms-manager/commit/69765687361140d216a8e1f11d6afa1a6648b3d8))

## [5.9.6](https://github.com/LindemannRock/craft-sms-manager/compare/v5.9.5...v5.9.6) (2026-03-04)


### Bug Fixes

* **jobs:** implement RetryableJobInterface in CleanupAnalyticsJob and CleanupLogsJob ([29860a6](https://github.com/LindemannRock/craft-sms-manager/commit/29860a6457f0833efeff9b7653e20f9285ec7abc))
* **SettingsController, Settings, ProvidersService, templates:** validate settings and improve error handling ([90123f6](https://github.com/LindemannRock/craft-sms-manager/commit/90123f60506c9710a508d0f0e4b9ae5aee3c7755))

## [5.9.5](https://github.com/LindemannRock/craft-sms-manager/compare/v5.9.4...v5.9.5) (2026-02-23)


### Bug Fixes

* **SettingsController:** validate and sanitize settings section parameter ([62cf142](https://github.com/LindemannRock/craft-sms-manager/commit/62cf142058489072f64158c923400420642936fa))

## [5.9.4](https://github.com/LindemannRock/craft-sms-manager/compare/v5.9.3...v5.9.4) (2026-02-22)


### Bug Fixes

* **SmsManager:** add setSettings method with no-op implementation ([a8209e6](https://github.com/LindemannRock/craft-sms-manager/commit/a8209e6180f580354bf9dfb6a8c9ba33cbf94494))

## [5.9.3](https://github.com/LindemannRock/craft-sms-manager/compare/v5.9.2...v5.9.3) (2026-02-17)


### Bug Fixes

* **analytics:** update sender ID and encoding permissions and loading states ([efb3f47](https://github.com/LindemannRock/craft-sms-manager/commit/efb3f476584dd2eb308c21f551a435ff761cb587))
* **dashboard:** prevent division by zero in SMS change calculation ([d2c414a](https://github.com/LindemannRock/craft-sms-manager/commit/d2c414a978113e1f58627d4fa76ce94a1ed55ec5))
* **ProvidersController, SenderIdsController:** update permissions for managing providers and sender IDs ([4b17ade](https://github.com/LindemannRock/craft-sms-manager/commit/4b17adef3b892e98ff8ab2a7d0d9861987d99624))


### Miscellaneous Chores

* add .gitattributes with export-ignore for Packagist distribution ([370fde6](https://github.com/LindemannRock/craft-sms-manager/commit/370fde63e4ef5acb8df9488737e932e647c4f3d9))
* switch to Craft License for commercial release ([dfd378a](https://github.com/LindemannRock/craft-sms-manager/commit/dfd378a2a31523b8371c7384695c50863ad353bb))

## [5.9.2](https://github.com/LindemannRock/craft-sms-manager/compare/v5.9.1...v5.9.2) (2026-02-07)


### Bug Fixes

* **AnalyticsController, SmsLogsController:** update date handling with DateFormatHelper ([3674411](https://github.com/LindemannRock/craft-sms-manager/commit/3674411346d9899c72cbeabd7fc7a7a069436878))

## [5.9.1](https://github.com/LindemannRock/craft-sms-manager/compare/v5.9.0...v5.9.1) (2026-02-05)


### Bug Fixes

* 'smsManager:viewSmsLogs' permission to logging navigation ([831b375](https://github.com/LindemannRock/craft-sms-manager/commit/831b3754f9cf3a9b2a57009c9efe8bb231b9a25b))

## [5.9.0](https://github.com/LindemannRock/craft-sms-manager/compare/v5.8.0...v5.9.0) (2026-02-05)


### Features

* **analytics:** enhance analytics record structure and SMS logging ([dd6598e](https://github.com/LindemannRock/craft-sms-manager/commit/dd6598ebbe370400775031c02d80e3a618656efb))
* **dashboard:** enhance SMS logs filtering and sorting functionality ([804bb7d](https://github.com/LindemannRock/craft-sms-manager/commit/804bb7dd62f70fb2794ea45044b04c24b97b473a))
* **dashboard:** implement quick actions menu for SMS management ([6019333](https://github.com/LindemannRock/craft-sms-manager/commit/60193336c6d8e67b095c144490868a8c876e5f98))
* **logs:** implement SMS logs page with filtering and sorting ([f20ca7f](https://github.com/LindemannRock/craft-sms-manager/commit/f20ca7f4027ebfd2994d5d14320c6035fc435373))
* **providers, senderIds:** add handle collision detection for config and database ([657f574](https://github.com/LindemannRock/craft-sms-manager/commit/657f57411c2483c5bf46afc843d2b18d3e2d4ec9))
* **providers:** pass provider to MPP-SMS settings component ([172a821](https://github.com/LindemannRock/craft-sms-manager/commit/172a821c175a902e5522a55c4617e75be1efd608))


### Bug Fixes

* **sms.twig:** correct export action URL for SMS logs ([c83184d](https://github.com/LindemannRock/craft-sms-manager/commit/c83184d2652e48383df9efa4b4f682faca373027))
* **SmsManager:** update [@since](https://github.com/since) version for getCpSections method to 5.9.0 ([7033c3b](https://github.com/LindemannRock/craft-sms-manager/commit/7033c3bedb9b1d16e2d4fec3d33412953782092f))


### Miscellaneous Chores

* **package.json:** update package name and version, add author and company details ([fd7c6b1](https://github.com/LindemannRock/craft-sms-manager/commit/fd7c6b1af7865609145114f0adc2b6f6f98ad77a))

## [5.8.0](https://github.com/LindemannRock/craft-sms-manager/compare/v5.7.0...v5.8.0) (2026-01-28)


### Features

* **analytics:** add export action and enhance analytics controller ([e810d48](https://github.com/LindemannRock/craft-sms-manager/commit/e810d489a0188a85800764ceee01cb1ca80c4f49))
* **logs:** improve log handling and bulk delete functionality ([e810d48](https://github.com/LindemannRock/craft-sms-manager/commit/e810d489a0188a85800764ceee01cb1ca80c4f49))
* **phone-input:** integrate lrPhoneInput component for phone number handling ([dd689f6](https://github.com/LindemannRock/craft-sms-manager/commit/dd689f6d44d5f5d904339533b32c383bc6ee9393))
* **providers:** enhance provider settings retrieval ([e810d48](https://github.com/LindemannRock/craft-sms-manager/commit/e810d489a0188a85800764ceee01cb1ca80c4f49))
* **security:** implement API endpoint validation ([e810d48](https://github.com/LindemannRock/craft-sms-manager/commit/e810d489a0188a85800764ceee01cb1ca80c4f49))
* **settings:** update settings interface and validation ([e810d48](https://github.com/LindemannRock/craft-sms-manager/commit/e810d489a0188a85800764ceee01cb1ca80c4f49))


### Bug Fixes

* **utilities:** correct permission checks for log management ([e810d48](https://github.com/LindemannRock/craft-sms-manager/commit/e810d489a0188a85800764ceee01cb1ca80c4f49))

## [5.7.0](https://github.com/LindemannRock/craft-sms-manager/compare/v5.6.0...v5.7.0) (2026-01-26)


### Features

* enhance log access control and improve dashboard table configuration ([94482ad](https://github.com/LindemannRock/craft-sms-manager/commit/94482ad9c1fb45d716d1306640ef8281485bdb35))


### Bug Fixes

* **jobs:** prevent duplicate scheduling of cleanup jobs ([b075767](https://github.com/LindemannRock/craft-sms-manager/commit/b075767cca6f68d26295c5d688e9eb457d810b96))

## [5.6.0](https://github.com/LindemannRock/craft-sms-manager/compare/v5.5.2...v5.6.0) (2026-01-24)


### Features

* refactor templates to use base plugin cp-table layout and centralize export/datetime helpers ([00be1fb](https://github.com/LindemannRock/craft-sms-manager/commit/00be1fb8e18324f3b6ad29e294be6523e55daae3))


### Bug Fixes

* filter options by grouping status and source in the provider dropdown ([88712cb](https://github.com/LindemannRock/craft-sms-manager/commit/88712cba6e1d65e63569de9ef8f0b63a8e4e3fa2))
* include sender ID value in logs display ([cd07182](https://github.com/LindemannRock/craft-sms-manager/commit/cd071824f33148a5f0c30dfe4c016290b5b756d5))

## [5.5.2](https://github.com/LindemannRock/craft-sms-manager/compare/v5.5.1...v5.5.2) (2026-01-22)


### Bug Fixes

* include actual sender ID in logs display ([2593115](https://github.com/LindemannRock/craft-sms-manager/commit/259311574008da804a3a6518f44da62306b6ab64))

## [5.5.1](https://github.com/LindemannRock/craft-sms-manager/compare/v5.5.0...v5.5.1) (2026-01-22)


### Bug Fixes

* enhance date formatting in logs display using Craft's formatter ([668ee4e](https://github.com/LindemannRock/craft-sms-manager/commit/668ee4e5b3196351eca075cd363cdd90ca1179ba))

## [5.5.0](https://github.com/LindemannRock/craft-sms-manager/compare/v5.4.0...v5.5.0) (2026-01-22)


### Features

* add phone number normalization and validation for MPP-SMS provider; enhance SMS log details display ([db3dea5](https://github.com/LindemannRock/craft-sms-manager/commit/db3dea53a7fc5f8f779faa5a20b9b1e48e0eaf64))

## [5.4.0](https://github.com/LindemannRock/craft-sms-manager/compare/v5.3.0...v5.4.0) (2026-01-22)


### Features

* add AJAX endpoint and auto-refresh functionality for SMS logs ([3fd504e](https://github.com/LindemannRock/craft-sms-manager/commit/3fd504ebbc0c0b22159093f85b5a80c3a05a6b46))
* add log status badge and filter menu for SMS logs ([6a88532](https://github.com/LindemannRock/craft-sms-manager/commit/6a88532427aab90098d5c5cf67612ee44604561d))


### Bug Fixes

* remove sortOrder from provider and sender ID configurations and update related documentation ([0542188](https://github.com/LindemannRock/craft-sms-manager/commit/05421888fc9ee4f2fa37bc0f9cfe81604902b40c))

## [5.3.0](https://github.com/LindemannRock/craft-sms-manager/compare/v5.2.5...v5.3.0) (2026-01-21)


### Features

* Add config file support for providers and sender IDs ([49a4cb5](https://github.com/LindemannRock/craft-sms-manager/commit/49a4cb5257e9cd1b7a0beb98246cb0597ee3bea0))
* Enhance MPP-SMS Provider with country restrictions, test API key support, and improved default item handling ([e4f1129](https://github.com/LindemannRock/craft-sms-manager/commit/e4f1129d35c305566b97150fe33c825cba3b150f))
* refactor provider/sender ID to use handles and remove isDefault columns ([e514a95](https://github.com/LindemannRock/craft-sms-manager/commit/e514a958d1b281d6f46d59f74eb4108cc8d29896))

## [5.2.5](https://github.com/LindemannRock/craft-sms-manager/compare/v5.2.4...v5.2.5) (2026-01-20)


### Bug Fixes

* update test SMS message content for clarity ([02b7af5](https://github.com/LindemannRock/craft-sms-manager/commit/02b7af51cedb5978efcd1c8152ba23c38dc425f0))

## [5.2.4](https://github.com/LindemannRock/craft-sms-manager/compare/v5.2.3...v5.2.4) (2026-01-16)


### Bug Fixes

* reorganize and standardize analytics templates ([91e46bc](https://github.com/LindemannRock/craft-sms-manager/commit/91e46bca90e421ffbcadb60e1a2d9f1654093c78))
* simplify details section in provider and sender ID templates ([53f247c](https://github.com/LindemannRock/craft-sms-manager/commit/53f247c23308056e85f2998ff6318abd4a02075b))
* update filename generation to use lower display name in Analytics and Logs controllers ([1ea5aaa](https://github.com/LindemannRock/craft-sms-manager/commit/1ea5aaae759aee63f203b9c4f316b3e3d1fff6c6))
* update PluginHelper bootstrap to include download permissions for logging ([0b5c1f9](https://github.com/LindemannRock/craft-sms-manager/commit/0b5c1f9f3144224760172a0926398552d4729ab5))

## [5.2.3](https://github.com/LindemannRock/craft-sms-manager/compare/v5.2.2...v5.2.3) (2026-01-14)


### Bug Fixes

* add RTL support for Arabic messages in SMS logs ([d43e2a5](https://github.com/LindemannRock/craft-sms-manager/commit/d43e2a5d6671d25fa1fd0f892dbb1c2fc577f6be))

## [5.2.2](https://github.com/LindemannRock/craft-sms-manager/compare/v5.2.1...v5.2.2) (2026-01-13)


### Bug Fixes

* adjust log date display to use the correct timezone ([4a6e327](https://github.com/LindemannRock/craft-sms-manager/commit/4a6e327721df00c176b19b564d915afa532ce757))

## [5.2.1](https://github.com/LindemannRock/craft-sms-manager/compare/v5.2.0...v5.2.1) (2026-01-13)


### Bug Fixes

* update copyright year to 2026 in multiple files ([2658d4c](https://github.com/LindemannRock/craft-sms-manager/commit/2658d4ce2e207f110bca94d9263c4102ff2e9be4))

## [5.2.0](https://github.com/LindemannRock/craft-sms-manager/compare/v5.1.0...v5.2.0) (2026-01-12)


### Features

* Implement analytics and logs cleanup jobs, enhance dashboard language breakdown, and improve SMS log details ([ee47b1e](https://github.com/LindemannRock/craft-sms-manager/commit/ee47b1e7fbfc723b0f2f227a5cc7673f9a5116f2))

## [5.1.0](https://github.com/LindemannRock/craft-sms-manager/compare/v5.0.0...v5.1.0) (2026-01-12)


### Features

* Add integration management and analytics for SMS encoding ([c8830f8](https://github.com/LindemannRock/craft-sms-manager/commit/c8830f8d53c3fc8cbff491f041eec9dcd9a996c6))

## 5.0.0 (2026-01-12)


### Features

* initial SMS Manager plugin implementation ([4a36c85](https://github.com/LindemannRock/craft-sms-manager/commit/4a36c8542b47dfc4563ec79b4246e04fb69e8e66))
