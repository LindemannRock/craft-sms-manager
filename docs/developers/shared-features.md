# Shared features

SMS Manager builds its Control Panel, settings, formatting, exports, and operational logs on the shared LindemannRock packages. This page helps plugin developers identify which behavior is inherited and where a project-level default can affect SMS Manager.

## `lindemannrock/base`

| Feature | Description |
|---------|-------------|
| `PluginHelper::bootstrap()` | Initializes base module, Twig globals, and logging configuration |
| `PluginHelper::applyPluginNameFromConfig()` | Overrides plugin name from config file |
| `SettingsConfigTrait` | Config file override detection and log level validation |
| `SettingsDisplayNameTrait` | Standardized plugin name helper methods |
| `SettingsPersistenceTrait` | Database persistence for Settings models |
| `PluginNameSettingsTrait` | Configurable Control Panel display name |
| `LogLevelSettingsTrait` | Log-level defaults and validation |
| `ItemsPerPageSettingsTrait` | Shared pagination setting and validation |
| `DateFormatSettingsTrait` | Cascading date and time formats |
| `DateRangeSettingsTrait` | Cascading default date range |
| `ExportFormatSettingsTrait` | Cascading CSV, JSON, and Excel availability |
| `DateRangeHelper` | Craft-timezone bounds for dashboards, widgets, and cleanup |
| `DateFormatHelper` | Localized dates for charts, AJAX rows, exports, and queue labels |
| `ExportHelper` | CSV, JSON, and Excel responses |
| `GeoHelper` | Country dial-code options and recipient-country handling |
| `CpNavHelper` | Permission- and setting-aware Control Panel navigation |

### Details

**PluginHelper::bootstrap()**

Provides plugin name helpers in Twig templates. See [Twig globals](twig-globals.md).

**PluginHelper::applyPluginNameFromConfig()**

Allows the display name to be customized through `config/sms-manager.php`.

**SettingsConfigTrait**

Settings can be overridden through `config/sms-manager.php`; overridden fields become read-only in the Control Panel. Debug logging requires Craft's `devMode`.

**SettingsDisplayNameTrait**

Provides the display-name variants exposed by `smsHelper`.

**SettingsPersistenceTrait**

Settings are stored in `smsmanager_settings` with type conversion for the declared boolean, integer, and string fields.

**GeoHelper**

Provides country dial codes and labels used in provider settings and recipient validation.

The other settings traits supply the General and Interface controls listed in [Configuration](../get-started/configuration.md). Date and export helpers apply those effective settings consistently across list views, analytics, widgets, and downloads.

---

## `lindemannrock/logging-library`

| Feature | Description |
|---------|-------------|
| `LoggingTrait` | Convenient logging methods (logInfo, logWarning, logError, logDebug) |
| `LoggingLibrary::addLogsNav()` | Adds "Logs" subnav to plugin CP navigation |

### Details

**LoggingTrait**

Provides standardized logging to dedicated plugin log files. See [Logging](../resources/logging.md) for the operational controls and viewer.

**LoggingLibrary::addLogsNav()**

Adds the permission-gated **Logs → System** and **Logs → SMS** menu to the plugin navigation when Logging Library is enabled.
