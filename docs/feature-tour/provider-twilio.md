# Twilio

Twilio is a global SMS gateway with worldwide coverage and automatic Unicode handling. Use it when you need to reach numbers outside the GCC, or want a single provider for everywhere.

## What you'll use it for

- Sending to recipients anywhere in the world
- Delivering non-Latin text (Twilio auto-detects and sends as UCS-2)
- Sending from a Twilio phone number, an alphanumeric sender ID, or a Messaging Service

## Settings

When you choose **Twilio** as the provider type, these settings appear:

| Setting | Required | Description |
|---------|----------|-------------|
| Account SID | Yes | Your Twilio Account SID. Accepts an environment variable |
| Auth Token | Yes | Your Twilio Auth Token. Accepts an environment variable |
| Allowed countries | No | Restrict to specific countries, or leave empty / `*` for all |

The sender itself is not a provider setting — it comes from the [Sender ID](sender-ids.md) record per message (a Twilio number in E.164, an alphanumeric sender ID, or a Messaging Service SID), so provider settings only hold account credentials.

![Twilio provider settings](../images/provider-twilio-settings.webp)

### Environment variables

```bash
# .env
TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_AUTH_TOKEN=your-auth-token
```

Reference them in the Account SID and Auth Token fields with `$TWILIO_ACCOUNT_SID` and `$TWILIO_AUTH_TOKEN`.

## How sending works

SMS Manager sends through Twilio's Programmable Messaging API (`POST https://api.twilio.com/2010-04-01/Accounts/{SID}/Messages.json`) using HTTP Basic authentication. The recipient is normalized to E.164 (with a leading `+`), and the message body is sent as-is — Twilio auto-detects encoding and sends non-GSM text as UCS-2.

A send is successful when Twilio returns a 2xx response with a message `sid` and no error code; SMS Manager stores that `sid` as the provider message ID. Failed sends discard raw gateway and transport text, then expose a bounded category, optional HTTP status, and correlation reference. Use that reference and the send time to investigate the attempt in the Twilio console.

## Capabilities

| Capability | Supported |
|------------|:---------:|
| Unicode | Yes |
| Delivery reports | Yes (capability) |
| Connection test | No |
| Per-sender development routing | No |

> [!NOTE]
> Twilio's delivery support is reported as a capability. SMS Manager marks a message `sent` once Twilio accepts it; advancing the status from Twilio's delivery callbacks is not part of the current release.

## Development senders

Twilio's test mode is account-level (Test Credentials with magic numbers) rather than per-message, so SMS Manager does not expose or honor the per-sender **Development** flag for Twilio senders. To test against Twilio without real delivery, configure a separate provider with your Twilio Test Credentials. A stale `isDev: true` database or config value is treated as inactive and never changes Twilio's send settings.

## Next steps

- [Sender IDs](sender-ids.md) — register the number or ID you'll send from
- [MPP-SMS](provider-mpp-sms.md) — the Kuwait/GCC alternative
- [Sending SMS](../developers/sending-sms.md) — send from code
