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

   ![Providers list](../images/providers-index.webp)

2. Choose a **Provider type** (MPP-SMS or Twilio). The settings below change to match.
3. Give it a **Name** (shown throughout the Control Panel) and a **Handle** (a stable identifier used by config files and the API).
4. Enter the provider's credentials. Each field accepts an environment variable, so you can store secrets in `.env`:

   ![Provider edit screen](../images/providers-edit.webp)

5. Optionally restrict **Allowed countries** — leave it empty (or `*`) to allow all, or pick specific countries.
6. Turn on **Enabled** and **Save**.

Each provider's exact fields are documented on its own page: [MPP-SMS](provider-mpp-sms.md) and [Twilio](provider-twilio.md).

## Enabled vs disabled

Only enabled providers can send. A disabled provider stays configured but any send routed to it returns a "Provider is disabled" error rather than going out. Toggle the status from the providers list or the edit screen.

## Transport Status and recovery

The providers list has two separate columns. **Status** is your Enabled/Disabled setting. **Transport** shows whether shared protection currently allows an attempt. Enabling a provider does not clear a hold.

Open the status filter above the list and use its **Transport Status** group to find available, held, unavailable, or unprotected providers. This combines with the enabled/disabled status, config source, and search filters. Choose **All** within the group to clear only the transport filter.

| Transport Status | Meaning |
|------------------|---------|
| **Available** | No active hold for this provider's accounts. This is not a live connection or delivery test |
| **On hold** | An account or provider-wide hold is active; open the provider for its scope and recovery time |
| **Unavailable** | Guard storage or account identity cannot be read; protected sends fail closed |
| **Not configured** | This custom provider has not adopted shared transport protection |

MPP-SMS and Twilio use the same protection service. Each provider interprets its own responses and defines the account identity and hold scope. Custom providers can [opt in](../developers/custom-providers.md#shared-transport-protection) without reimplementing persistence or locking.

An orange notice at the top of the provider page appears when one or more accounts are on hold. A red notice indicates that transport status could not be checked.

Open a provider to see its **Transport Status** sidebar card below **Provider Info**, including on config-defined providers. It shows the hold type, main/development account lane, activation time, automatic recovery time when applicable, and active or expired status. Credentials, fingerprints, recipients, raw failures and IP addresses are never displayed. The list shows **On hold** if any displayed account lane is held; an unaffected lane can still send unless a provider-wide hold also applies. **All accounts** means all accounts for that provider type in this installation—for example, all MPP accounts, without affecting Twilio.

### Release a hold safely

The providers list offers **Actions → Release hold** to users with **Edit providers** permission when a hold is active. For one hold, this opens confirmation with the provider handle and account scope. For several holds, it opens the provider’s sidebar holds so you can select the specific hold.

1. Check the reason and affected account. Resolve the issue in the gateway account; do not repeatedly send Test SMS as a recovery probe.
2. For a **Safety hold**, stop all sending processes and reconcile any uncertain delivery first. This hold may have been installed during setup or left after an interrupted request. It does not prove a request is currently running.
3. A user with **Edit providers** permission selects **Release hold** in the chosen hold’s **Actions** field and confirms. The action is POST-only and CSRF-protected. If admission is busy or the row has changed, release fails safely; refresh before trying again.
4. Review the remaining holds. Releasing one account leaves other accounts and any provider-wide hold intact.

**Account unavailable**, **Safety hold**, and other rows without a recovery time require manual release. Timed holds expire automatically and may also be released early through the same confirmed action after recovery is verified. Expiration makes a later ordinary attempt eligible; it does not send anything itself.

Release clears only the selected guard state. It never contacts a gateway, sends a message, creates or restarts a queue job, retries failed work, or runs a campaign. The release history records the provider type, bounded scope/lane/reason, operator user ID and time without private account identity.

### Shared storage and concurrent sending

Protected requests are serialized per provider type using a database advisory lock and durable guard rows. MPP and Twilio have independent locks and holds. Within one type, a transport hold affects all accounts in this Craft installation, across every provider alias, sender and host. An account hold follows the provider's resolved account identity across aliases. See [MPP credential lanes](provider-mpp-sms.md#credential-lanes-and-shared-state) and [Twilio recovery](provider-twilio.md#transport-protection).

Every host must use the same primary writer and Craft security key. Database connection pooling must preserve session locks. Cache is not the authority: cache loss or flushing cannot remove a hold. Missing or outdated tables, unavailable storage, and sends inside an open database transaction fail closed. Changing the security key or restoring an older database requires stopped-sender review because it can change account identity or rewind guard state. Separate Craft installations and other applications do not share this protection, even if they use the same outbound IP.

Serialization limits simultaneous requests for a provider type. The service commits an in-flight safety marker before calling the gateway; process death leaves a manual hold. Ordinary handled transport failures release that marker without retrying the uncertain message. The calling application retains ownership of its queue and recovery policy.

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
