# Sender IDs

A sender ID is the name or number a recipient sees a message come from. Register the sender IDs your provider has approved, and SMS Manager sends from them.

Most gateways require sender IDs to be registered with them in advance (an alphanumeric ID like `MYBRAND`, or a phone number). SMS Manager lets you manage those approved senders in one place, attach each to a provider, and pick which is the default.

## What you'll use it for

- Sending from a recognizable brand name instead of a random number
- Keeping several senders (e.g. "Marketing", "Alerts") and choosing per message
- Marking a sender as development-only so it routes through a test key (MPP-SMS)
- Letting templates and code address a sender by a stable handle

## Create your first sender ID

In the Control Panel — no code:

1. Go to **SMS Manager → Sender IDs** and click **New Sender ID**.

   ![Sender IDs list](images/sender-ids-index.webp)

2. Fill in the fields:

   ![Sender ID edit screen](images/sender-ids-edit.webp)

3. Turn on **Enabled** and **Save**.

### Fields

| Field | Required | Description |
|-------|----------|-------------|
| Name | Yes | A label for your team (e.g. "Marketing") |
| Handle | Yes | A stable identifier used by config files and the API. Generated from the name if left blank, and kept unique |
| Sender ID | Yes | The value registered with your provider — an alphanumeric ID (typically up to 11 characters) or a phone number |
| Provider | Yes | The provider this sender belongs to |
| Description | No | Optional notes |
| Development | No | Marks this sender as development-only (see below) |

## Enabled vs disabled

Only enabled sender IDs can send. A disabled sender stays configured, but any send routed to it returns a "Sender ID is disabled" error. Toggle the status from the list or the edit screen, or in bulk.

## The default sender ID

Sends that don't name a sender use the **default sender ID**. Set it with the **Default Sender ID** toggle on the sender's edit screen, from the sender IDs list, or under **SMS Manager → Settings → General**. If no default is set, SMS Manager falls back to the first enabled sender (filtered by the provider in play).

Like the default provider, the default sender is referenced by handle and fails loud: if the configured handle doesn't resolve to an enabled sender, sends that rely on it return a clear "No sender ID configured" error instead of silently using another sender. See [Configuration](../get-started/configuration.md#general).

## Development senders

Switching a sender's **Development** flag on tells providers to treat its traffic as test traffic. What that does depends on the provider:

- **MPP-SMS** — sends with the provider's **Development API Key** when one is configured, so test messages route through a separate account that still delivers.
- **Twilio** — has no effect; Twilio's test mode is account-level. Use a separate provider with Test Credentials instead.

See [MPP-SMS](provider-mpp-sms.md#development-senders) and [Twilio](provider-twilio.md#development-senders).

## Config-defined sender IDs

Sender IDs can be declared in `config/sms-manager.php`. Config senders show a **Config** badge, are read-only in the Control Panel, and take precedence over a database sender with the same handle. Reference the provider by its handle. See [Configuration](../get-started/configuration.md#defining-providers-and-sender-ids-in-config).

## Addressing a sender from code

Because every sender has a stable handle, code can send through one by name — including config-only senders that have no database ID:

```php
use lindemannrock\smsmanager\SmsManager;

SmsManager::$plugin->sms->sendWithHandle('+96512345678', 'Hello!', 'marketing', 'en');
```

See [Sending SMS](../developers/sending-sms.md) for the full API.

## Next steps

- [Providers](providers.md) — the gateways senders belong to
- [Test SMS](test-sms.md) — send a one-off message from a sender
- [Sending SMS](../developers/sending-sms.md) — send from code
