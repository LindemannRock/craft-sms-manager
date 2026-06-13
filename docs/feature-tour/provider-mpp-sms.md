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

![MPP-SMS provider settings](images/provider-mpp-sms-settings.webp)

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

## Phone number handling

Before sending, MPP-SMS normalizes the recipient number: it converts Arabic and Persian numerals to Western digits, strips spaces and hidden characters, and removes `+` or `00` prefixes. When the provider has an **Allowed countries** list, it also repairs common mistakes — a duplicated country code (`96596594400999` → `96594400999`) or a local number missing its country code (`94400999` → `96594400999` for Kuwait) — and rejects numbers that don't match an allowed country.

Country repair is supported for Kuwait, Saudi Arabia, the UAE, Bahrain, Qatar, Oman, Egypt, Jordan, Lebanon, and Iraq.

## Development senders

A sender ID can be marked **Development**. When an MPP-SMS provider has a **Development API Key** configured, messages from a development sender are sent with that key instead of the main one — useful for routing test traffic through a separate account that still delivers. Without a development key, development senders use the main key.

## Capabilities

| Capability | Supported |
|------------|:---------:|
| Unicode (Arabic) | Yes |
| Delivery reports | No |
| Connection test | No |

## Responses and errors

A send is treated as successful when the gateway response contains `OK`; SMS Manager extracts the provider message ID from the response and stores it on the log. Failures store the raw response and surface common gateway errors — invalid API key, unregistered sender ID, invalid mobile number, or insufficient balance. See [Troubleshooting](../resources/troubleshooting.md).

## Next steps

- [Sender IDs](sender-ids.md) — register the names you'll send from
- [Twilio](provider-twilio.md) — the global alternative
- [Sending SMS](../developers/sending-sms.md) — send from code
