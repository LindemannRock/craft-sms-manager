# Quickstart

Connect a provider, register a sender ID, and send a real test message from the Control Panel — no code required.

## Before you start

Complete [Installation & Setup](installation.md#post-install-setup) first. You should have:

- SMS Manager installed and enabled
- Credentials for an MPP-SMS or Twilio account
- A sender name, phone number, or Messaging Service SID approved by that provider

## 1. Add a provider

A provider is your connection to an SMS gateway.

1. Go to **SMS Manager → Providers** and click **New Provider**.
2. Choose a provider type — **MPP-SMS** (Kuwait) or **Twilio** (global).
3. Enter the credentials:
   - **MPP-SMS** — your API key.
   - **Twilio** — your Account SID and Auth Token.
4. Make sure **Enabled** is on, and save.

See [Providers](../feature-tour/providers.md) for every setting.

## 2. Add a sender ID

A sender ID is the name or number recipients see a message come from.

1. Go to **SMS Manager → Sender IDs** and click **New Sender ID**.
2. Fill in:
   - **Name** — a label for your team (e.g. "Marketing").
   - **Sender ID** — the value registered with your provider (an alphanumeric ID like `MYBRAND`, or a Twilio number in E.164).
   - **Provider** — the provider you just created.
3. Enable it and save.

## 3. Set your defaults

Go to **SMS Manager → Settings → General** and pick your **Default Provider** and **Default Sender ID**. Sends that don't name a provider or sender will use these.

## 4. Send a test message

1. Go to **SMS Manager → Settings → Test SMS**.
2. Pick the provider and sender ID, enter a recipient number and a short message, then click **Send Test SMS**.
3. You'll see the result inline — success with a provider message ID, or safe diagnostic details if it failed.

Open **SMS Manager** (the dashboard) or **SMS Manager → Logs → SMS** to confirm the message was recorded.

## What's next

- [Configuration](configuration.md) — customize behavior and lock settings per environment
- [Sending SMS](../developers/sending-sms.md) — trigger sends from your own code
- [Logging](../resources/logging.md) — inspect plugin-level diagnostics
- [Feature tour](../feature-tour/overview.md) — explore everything SMS Manager can do
