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

## Transport protection

Twilio uses SMS Manager's [shared Transport Status and recovery workflow](providers.md#transport-status-and-recovery). Its account identity is the resolved Account SID, so provider aliases and Auth Token rotation share the same account hold. Twilio uses the main lane; MPP holds do not affect it.

| Response | Hold | Recovery |
|----------|------|----------|
| Error code `20003` (authentication) or `20005` (inactive account) | Account unavailable | Manual release after the account/credentials are corrected |
| HTTP `429` | Account rate limit | 60 seconds |
| HTTP `500`–`599` | All Twilio accounts in this installation | 60 seconds |
| Other failures, including recipient-specific errors | No new shared hold | Return the sanitized failure to the caller |

The 60-second delay is SMS Manager's conservative local policy, not a provider-promised recovery time. Automatic expiry permits a future ordinary attempt; it never replays a message. A failed request, especially a server error, may have an uncertain delivery outcome, so reconcile it before any caller-owned retry.

These classifications use Twilio's documented [HTTP responses](https://www.twilio.com/docs/usage/twilios-response), [authentication error](https://www.twilio.com/docs/api/errors/20003), [inactive-account error](https://www.twilio.com/docs/api/errors/20005), and [rate-limit error](https://www.twilio.com/docs/api/errors/20429). Raw error messages are discarded. Redirects and automatic retries are disabled.

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
