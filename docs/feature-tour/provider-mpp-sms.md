# MPP-SMS

MPP-SMS is a Kuwait SMS provider with first-class support for Arabic and English messages. Use it when your recipients are in Kuwait or nearby GCC countries.

## What you'll use it for

- Sending to Kuwait and GCC mobile numbers
- Delivering Arabic messages with correct encoding
- Running a separate development sender against a test API key

## Settings

When you choose **MPP-SMS** as the provider type, these settings appear:

| Setting | Required | Description |
|---------|----------|-------------|
| API Key | Yes | Your MPP-SMS API key. Accepts an environment variable (e.g. `$MPP_SMS_API_KEY`) |
| API URL | No | Custom endpoint. Defaults to `https://api.mpp-sms.com/api/send.aspx`. Must be HTTPS |
| Allowed countries | No | Restrict to specific countries, or leave empty / `*` for all |
| Development API Key | No | Alternate API key used by sender IDs marked as development. Accepts an environment variable |

Set credentials in the Control Panel under **SMS Manager → Providers**, or declare them in `config/sms-manager.php`.

![MPP-SMS provider settings](../images/provider-mpp-sms-settings.webp)

### Environment variables

Keep secrets in `.env` and reference them in the provider settings:

```bash
# .env
MPP_SMS_API_KEY=your-api-key-here
```

```text
$MPP_SMS_API_KEY
```

## How messages are encoded

MPP-SMS picks encoding from the message language:

- **English** (`'en'`) — URL-encoded text.
- **Arabic** (`'ar'`) — UCS-2 (hex) encoding, so Arabic characters arrive intact.

Pass the correct language when sending so the right encoding is used. See [Sending SMS](../developers/sending-sms.md).

This is MPP-SMS's existing gateway wire-format contract. It is separate from SMS Manager's analytics and Test SMS calculator, which classify GSM-7 or UCS-2 from message content. The provider handle remains `mpp-sms`.

## Phone number handling

Before sending, MPP-SMS normalizes the recipient number: it converts Arabic and Persian numerals to Western digits, strips spaces and hidden characters, and removes `+` or `00` prefixes. When the provider has an **Allowed countries** list, it also repairs common mistakes — a duplicated country code (`96596594400999` → `96594400999`) or a local number missing its country code (`94400999` → `96594400999` for Kuwait) — and rejects numbers that don't match an allowed country.

Country repair is supported for Kuwait, Saudi Arabia, the UAE, Bahrain, Qatar, Oman, Egypt, Jordan, Lebanon, and Iraq.

## Development senders

A sender ID attached to MPP-SMS can be marked **Development**. When the provider has a **Development API Key** configured, messages from that sender are sent with the development key instead of the main one — useful for routing test traffic through a separate account that still delivers. Without a development key, development senders use the main key.

## Capabilities

| Capability | Supported |
|------------|:---------:|
| Unicode (Arabic) | Yes |
| Delivery reports | No |
| Connection test | No |
| Per-sender development routing | Yes |

## Responses and errors

A send is treated as successful only when the HTTP status is 2xx and the trimmed gateway response starts with the complete `OK` token (`OK` or `OK,`); SMS Manager extracts the provider message ID from the response and stores it on the log. Failed sends discard raw gateway and transport text, then expose a bounded category, optional HTTP status, and correlation reference. Use that reference with the MPP-SMS dashboard and [Troubleshooting](../resources/troubleshooting.md).

## Transport Status and recovery

Open **SMS Manager → Providers → your MPP provider** and find **Transport Status**. This section also appears for providers defined in configuration. It shows the hold type, account lane, activation time, recovery time where available, and whether the hold is active or expired. It never shows credentials, credential fingerprints, recipients, raw failures or IP addresses.

An **Account unavailable** hold stops the affected credential immediately after MPP reports `error,user is inactive`. That wording may describe more than a zero balance, so check the account state with MPP rather than assuming that a top-up alone resolves it. Account holds require manual release.

An **IP blocked** hold stops all MPP accounts in this installation. For a reported duration from zero to 120 minutes, recovery is scheduled for the reported duration (at least one minute) plus a one-minute safety margin. After that time the next ordinary send may proceed; expiration itself sends nothing. A duration outside that bound creates a manual transport hold.

To release a hold:

1. Resolve the account or IP problem with your provider. Do not repeatedly use Test SMS as a recovery probe.
2. For **In-flight or interrupted request**, stop every sending process first and reconcile the uncertain delivery outcome. This state can remain after a process dies during a request; it never expires automatically.
3. A user with **Edit providers** permission selects **Release hold** on the relevant row and confirms the action. The release fails safely if another send currently holds admission or the selected state has changed; refresh before trying again.
4. Review the remaining rows. Releasing an account hold leaves a transport hold intact, and vice versa.

Release records retain the operator's user ID, time and bounded hold details. Releasing a hold only clears that guard state. It does not contact MPP, send messages, create or restart jobs, retry failed jobs, or run campaigns. Timed IP holds may be released early through this same confirmed action after the operator has verified recovery.

### Credential lanes and shared state

Account holds follow the actual resolved API key across provider aliases and sender IDs. A distinct development key has its own hold; development senders that fall back to the main key share the main hold. Identical main and development keys also share the main row. Different keys cannot be inferred to belong to the same MPP billing account.

The transport hold applies to this installation's entire MPP transport, across all accounts and hosts. It cannot coordinate another Craft installation or another application sharing the same outbound IP.

All MPP attempts are serialized using a database advisory lock and durable database state. Every host must share the same primary writer and Craft security key; connection-pooling infrastructure must preserve session locks. Craft cache is not the authority, so flushing cache cannot remove a hold. Missing guard tables, unavailable storage, or a send inside an uncommitted database transaction block transport. Changing the security key or restoring an older database requires a stopped-sender recovery review because it can change or rewind guard identity/state.

This serialization limits concurrent MPP throughput. SMS Manager owns transport admission; the caller still owns its pending messages and retry policy. Boolean callers receive `false` during a hold. Consumers that need to pause pending work must use the [detailed result metadata](../developers/sending-sms.md#failure-metadata-and-queue-ownership).

## Next steps

- [Sending SMS](../developers/sending-sms.md) — structured results and queue ownership
- [Troubleshooting](../resources/troubleshooting.md) — recover safely when sending stops
