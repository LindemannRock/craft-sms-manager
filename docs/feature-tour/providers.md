# Providers

A provider is your connection to an SMS gateway — the service that actually delivers text messages. Configure at least one provider and SMS Manager can send.

SMS Manager ships with two providers and is built so more can be added. You can run several at once (for example, a regional gateway for the GCC and Twilio for everywhere else) and route each message to the right one.

## What you'll use it for

- Connecting your SMS account so the plugin can send
- Running more than one gateway side by side
- Restricting a provider to specific countries
- Keeping credentials out of the database by reading them from environment variables

## Built-in providers

| Provider | Best for | Unicode | Delivery reports | Credentials |
|----------|----------|:-------:|:----------------:|-------------|
| [MPP-SMS](provider-mpp-sms.md) | Kuwait and nearby GCC numbers, Arabic + English | Yes | No | API key |
| [Twilio](provider-twilio.md) | Global coverage | Yes | Yes (capability) | Account SID + Auth Token |

> [!NOTE]
> Twilio reports delivery support as a gateway capability. SMS Manager records a message as `sent` once Twilio accepts it; consuming Twilio's delivery callbacks to advance the status further is not part of the current release.

Need a different gateway? See [Custom providers](../developers/custom-providers.md).

## Create your first provider

In the Control Panel — no code:

1. Go to **SMS Manager → Providers** and click **New Provider**.

   ![Providers list](images/providers-index.webp)

2. Choose a **Provider type** (MPP-SMS or Twilio). The settings below change to match.
3. Give it a **Name** (shown throughout the Control Panel) and a **Handle** (a stable identifier used by config files and the API).
4. Enter the provider's credentials. Each field accepts an environment variable, so you can store secrets in `.env`:

   ![Provider edit screen](images/providers-edit.webp)

5. Optionally restrict **Allowed countries** — leave it empty (or `*`) to allow all, or pick specific countries.
6. Turn on **Enabled** and **Save**.

Each provider's exact fields are documented on its own page: [MPP-SMS](provider-mpp-sms.md) and [Twilio](provider-twilio.md).

## Enabled vs disabled

Only enabled providers can send. A disabled provider stays configured but any send routed to it returns a "Provider is disabled" error rather than going out. Toggle the status from the providers list or the edit screen.

## The default provider

Sends that don't name a provider use the **default provider**. Set it with the **Default Provider** toggle on the provider's edit screen, from the providers list, or under **SMS Manager → Settings → General**. If no default is set, SMS Manager falls back to the first enabled provider.

The default is referenced by handle. If the configured default handle doesn't resolve to an enabled provider, sends that rely on it fail with an explicit "No provider configured" error instead of silently using a different gateway — fix the handle to recover. See [Configuration](../get-started/configuration.md#general).

## Country filtering

A provider's **Allowed countries** list restricts which recipient numbers it will accept. When set, SMS Manager normalizes the recipient number, then rejects it if it doesn't belong to an allowed country. An empty list or `['*']` means all countries are allowed. This is enforced at send time, so a misrouted number fails fast with a clear error.

## Config-defined providers

Providers can also be declared in `config/sms-manager.php`. Config providers show a **Config** badge, are read-only in the Control Panel, and take precedence over a database provider with the same handle. This is the cleanest way to keep credentials in code and vary them per environment. See [Configuration](../get-started/configuration.md#defining-providers-and-sender-ids-in-config).

## Outbound request security

Before any request to a provider's API, SMS Manager validates the endpoint against a safe-by-default policy: HTTPS required, private and loopback networks blocked, redirects disabled, and only port 443 allowed. You can allowlist specific hosts globally or per provider. See [Configuration](../get-started/configuration.md#outbound-request-security).

## Testing a provider

Use [Test SMS](test-sms.md) under **SMS Manager → Settings** to send a real one-off message and confirm credentials work end to end.

Providers can also declare a connection-test capability for a credentials check without sending. Neither built-in provider supports this — MPP-SMS and Twilio have no test endpoint — but a [custom provider](../developers/custom-providers.md) can implement it.

## Next steps

- [MPP-SMS](provider-mpp-sms.md) — Kuwait/GCC gateway settings
- [Twilio](provider-twilio.md) — global gateway settings
- [Sender IDs](sender-ids.md) — the names messages are sent from
