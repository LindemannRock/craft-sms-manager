# Permissions

SMS Manager registers granular permissions that can be assigned to user groups via **Settings → Users → User Groups → [Group Name] → SMS Manager**.

## Permission structure

### Providers

| Permission | Description |
|------------|-------------|
| **`smsManager:manageProviders`** | Parent — access the Providers section |
| └─ `smsManager:createProviders` | Create providers |
| └─ `smsManager:editProviders` | Edit providers |
| └─ `smsManager:deleteProviders` | Delete providers |

### Sender IDs

| Permission | Description |
|------------|-------------|
| **`smsManager:manageSenderIds`** | Parent — access the Sender IDs section |
| └─ `smsManager:createSenderIds` | Create sender IDs |
| └─ `smsManager:editSenderIds` | Edit sender IDs |
| └─ `smsManager:deleteSenderIds` | Delete sender IDs |

### Analytics

| Permission | Description |
|------------|-------------|
| **`smsManager:viewAnalytics`** | Parent — view the Analytics section |
| └─ `smsManager:exportAnalytics` | Export analytics |
| └─ `smsManager:clearAnalytics` | Clear analytics |

### Logs

| Permission | Description |
|------------|-------------|
| **`smsManager:viewLogs`** | Parent — access logs |
| └─ `smsManager:viewSystemLogs` | View system logs |
| &nbsp;&nbsp;&nbsp;└─ `smsManager:downloadSystemLogs` | Download system logs |
| └─ `smsManager:viewSmsLogs` | View SMS logs (and the dashboard) |
| &nbsp;&nbsp;&nbsp;└─ `smsManager:exportSmsLogs` | Export SMS logs |
| &nbsp;&nbsp;&nbsp;└─ `smsManager:deleteSmsLogs` | Delete SMS logs |

### Settings

| Permission | Description |
|------------|-------------|
| `smsManager:manageSettings` | Manage settings (includes the Test SMS page) |

## Checking permissions

In Twig:

```twig
{% if currentUser.can('smsManager:manageProviders') %}
    {# User has permission #}
{% endif %}
```

In PHP:

```php
if (Craft::$app->getUser()->checkPermission('smsManager:manageProviders')) {
    // User has permission
}

// In a controller
$this->requirePermission('smsManager:manageProviders');
```

## Nested permission pattern

Craft's nested permissions are a UI convenience — the parent permission does not automatically grant child permissions.

- **"Manage" / "View" parents** (e.g. `manageProviders`, `viewAnalytics`, `viewSmsLogs`) grant access to a section and control CP subnav visibility.
- **Write and action children** (e.g. `createProviders`, `exportAnalytics`, `deleteSmsLogs`) control specific operations.

To give read-only access to a section, grant just the parent. For full access, also grant the specific child permissions needed.

> [!NOTE]
> The dashboard (the **SMS Manager** landing page) requires `smsManager:viewSmsLogs`. A user without it lands on the first section they can access. The **Clear all analytics** utility requires `smsManager:clearAnalytics`; **Clear all SMS logs** requires `smsManager:deleteSmsLogs`.
