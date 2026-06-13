# Features overview

SMS Manager is the SMS gateway layer for Craft CMS: connect a provider, register your sender IDs, and send text messages from your own code — with delivery logs, analytics, and granular permissions built in.

> [!TIP]
> New to SMS Manager? Start with [Installation](../get-started/installation.md) and the [Quickstart](../get-started/quickstart.md), then come back here for a tour.

## What it does

SMS Manager handles the plumbing of sending SMS: it talks to an SMS gateway, picks the right provider and sender ID, normalizes the recipient's phone number, sends the message, and records the result. Your code (or another plugin) calls one method; SMS Manager does the rest and gives you a delivery log and analytics for every message.

It's an infrastructure plugin — there's no campaign builder or contact list here. It gives you a clean, permission-gated place to manage gateways and a single PHP entry point for sending.

## What you'll use it for

- Sending transactional SMS — order confirmations, OTPs, alerts — from an entry save, a form submission, or a custom plugin
- Managing multiple gateways and sender IDs in one place, with per-environment defaults
- Sending Arabic and English messages with correct encoding
- Keeping an auditable log of every message sent, with provider responses and errors
- Seeing how many messages went out, how many succeeded, and which plugin triggered them

## Core capabilities

- **[Providers](providers.md)** — Connect an SMS gateway. Ships with [MPP-SMS](provider-mpp-sms.md) (Kuwait, Arabic + English) and [Twilio](provider-twilio.md) (global). Each provider has its own credentials and an optional country allowlist.

- **[Sender IDs](sender-ids.md)** — Register the names or numbers messages are sent from. Enable or disable each one, mark senders as development-only, and set a default.

- **[Analytics](analytics.md)** — Daily sent/failed counts, success rate, language and encoding breakdown, and per-provider, per–sender ID, and per-site performance. Filter by site, language, provider, sender ID, and date range.

- **[SMS logs](sms-logs.md)** — A full delivery history with recipient, message, status, provider response, and error. Filter, search, and export to CSV, JSON, or Excel.

- **[Test SMS](test-sms.md)** — Send a one-off message from the Control Panel to verify a provider and sender ID before wiring anything up.

- **[Sending SMS](../developers/sending-sms.md)** — The PHP API other plugins and your own code use to send. Source-plugin tracking attributes each message back to what triggered it.

- **[Custom providers](../developers/custom-providers.md)** — Add support for another gateway by extending a base class.

## The dashboard

Opening **SMS Manager** lands on the dashboard — an at-a-glance view of messaging activity: messages sent today (with a yesterday comparison), the last 7 days' success rate, failures today, how many providers are configured and enabled, and the most recent messages.

![SMS Manager dashboard](images/overview-dashboard.webp)

The dashboard requires the **View SMS logs** permission and SMS logs to be enabled. If a user can't see it, they land on the first section they do have access to.

## CP utilities

SMS Manager adds maintenance actions under **Utilities → SMS Manager**: **Clear all analytics** and **Clear all SMS logs**. Both ask for confirmation and respect the relevant permissions. Day-to-day, you rarely need these — retention trimming runs automatically (see [Configuration](../get-started/configuration.md)).

## Next steps

1. [Install the plugin](../get-started/installation.md)
2. [Add a provider](providers.md)
3. [Register a sender ID](sender-ids.md)
4. [Send your first message](../get-started/quickstart.md)
