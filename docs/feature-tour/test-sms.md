# Test SMS

Test SMS sends a real one-off message from the Control Panel so you can confirm a provider and sender ID work — before wiring SMS into a form, a workflow, or your own code.

## What you'll use it for

- Verifying new provider credentials actually deliver
- Checking that a sender ID is approved and sending correctly
- Confirming Arabic messages encode properly end to end
- Reproducing a delivery problem with a known recipient

## Sending a test

Go to **SMS Manager → Settings → Test SMS**.

![Test SMS page](images/test-sms-page.webp)

1. **Provider** — choose which gateway to send through. The default provider is pre-selected.
2. **Sender ID** — choose the sender. The list updates to the senders available for the chosen provider, and the default sender is pre-selected. Development senders are marked only when their provider supports per-sender development routing.
3. **Recipient** — enter the destination number. If you paste a number from a country the provider doesn't allow, you'll be warned before sending.
4. **Message** — type the text to send.
5. **Language** — choose the message language (`en` or `ar`). This controls text direction and remains available to the provider, but it does not determine the displayed SMS encoding.
6. Click **Send**.

As you type, the page shows the content-derived encoding, Unicode character count, encoding-unit count, and SMS segment count. GSM-7 extension characters such as `^`, `{`, and `€` use two septets. GSM-7 messages fit 160 septets in one segment or 153 per multipart segment. UCS-2 messages fit 70 UTF-16 code units in one segment or 67 per multipart segment; emoji and other astral characters use two units. Empty input reports zero characters and zero segments.

The result appears inline — on success, the provider message ID and timing; on failure, safe diagnostic metadata with the provider, failure category, optional HTTP status, and a correlation reference. The send is recorded in [SMS Logs](sms-logs.md) with the source `sms-manager-test`, so you can trace it like any other message without exposing transport URLs, credentials, or message content in the error.

For an MPP-SMS development sender, the page reports whether the Development API Key is configured. Unsupported providers such as Twilio never show a Development marker or development-key message, even if an old database row or config entry contains `isDev: true`.

> [!NOTE]
> The Test SMS page lives under Settings and requires the **Manage settings** permission. It sends through the same pipeline as a production send, so a successful test means real credentials and a real, deliverable message.

## How it routes

Test SMS addresses the sender by its handle, which means it routes correctly even for config-only senders (which have no database ID) — the message goes to exactly the sender you picked rather than falling back to a default. This mirrors the [`sendWithHandle()`](../developers/sending-sms.md) API.

## Next steps

- [Providers](providers.md) — set up the gateway you're testing
- [SMS logs](sms-logs.md) — find your test message and delivery details
- [Sending SMS](../developers/sending-sms.md) — send the same way from code
