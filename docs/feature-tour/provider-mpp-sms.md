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

Use the [shared hold-release procedure](providers.md#release-a-hold-safely) to recover. A **Safety hold** may have been installed during setup or left by an interrupted request; it is not proof that a request is running. It has no automatic expiry. Stop sending processes and reconcile any uncertain delivery before release. Timed IP holds may be released early through the same confirmed administrative action after recovery is verified.

### Credential lanes and shared state

Account holds follow the actual resolved API key across provider aliases and sender IDs. A distinct development key has its own hold; development senders that fall back to the main key share the main hold. Identical main and development keys also share the main row. Different keys cannot be inferred to belong to the same MPP billing account.

The transport hold applies to this installation's entire MPP transport, across all accounts and hosts. It cannot coordinate another Craft installation or another application sharing the same outbound IP.

All MPP attempts use the [shared database admission service](providers.md#shared-storage-and-concurrent-sending). Cache loss does not clear holds. The service serializes MPP requests across workers and hosts, which limits concurrent throughput. Boolean callers receive `false` during a hold; consumers that need to pause pending work must use the [detailed result metadata](../developers/sending-sms.md#failure-metadata-and-queue-ownership).

## Next steps

- [Sending SMS](../developers/sending-sms.md) — structured results and queue ownership
- [Troubleshooting](../resources/troubleshooting.md) — recover safely when sending stops
