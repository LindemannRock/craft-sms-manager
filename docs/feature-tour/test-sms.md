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
2. **Sender ID** — choose the sender. The list updates to the senders available for the chosen provider, and the default sender is pre-selected. Development senders are marked.
3. **Recipient** — enter the destination number. If you paste a number from a country the provider doesn't allow, you'll be warned before sending.
4. **Message** — type the text to send.
5. **Language** — choose the message language (`en` or `ar`) so the right encoding is used.
6. Click **Send**.

The result appears inline — on success, the provider message ID and timing; on failure, the exact provider error. The send is recorded in [SMS Logs](sms-logs.md) with the source `sms-manager-test`, so you can trace it like any other message.

> [!NOTE]
> The Test SMS page lives under Settings and requires the **Manage settings** permission. It sends through the same pipeline as a production send, so a successful test means real credentials and a real, deliverable message.

## How it routes

Test SMS addresses the sender by its handle, which means it routes correctly even for config-only senders (which have no database ID) — the message goes to exactly the sender you picked rather than falling back to a default. This mirrors the [`sendWithHandle()`](../developers/sending-sms.md) API.

## Next steps

- [Providers](providers.md) — set up the gateway you're testing
- [SMS logs](sms-logs.md) — find your test message and its provider response
- [Sending SMS](../developers/sending-sms.md) — send the same way from code
