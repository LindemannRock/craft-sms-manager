# Analytics

Analytics tells you how much SMS you're sending, how much is succeeding, and where it's coming from — without digging through individual logs.

Every message SMS Manager sends is recorded as an analytics event (when analytics is enabled). The Analytics screen aggregates those events into totals, a success rate, a language and encoding breakdown, and per-provider, per–sender ID, and per-site views.

## What you'll use it for

- Checking sent vs failed totals and the success rate over a period
- Seeing the English/Arabic split and encoding mix
- Comparing how different providers and sender IDs are performing
- Reporting per site in a multisite install

## Viewing analytics

Go to **SMS Manager → Analytics**. The screen shows:

- **Summary** — total sent and failed, plus the success rate (sent ÷ sent + failed)
- **Daily trend** — sent and failed per day across the range
- **Language breakdown** — English, Arabic, and other counts, plus an encoding breakdown
- **Provider breakdown** — sent/failed per provider
- **Sender ID breakdown** — sent/failed per sender ID
- **Site breakdown** — totals per site

![SMS Manager analytics](images/analytics-overview.webp)

## Filters

Narrow the view with the filter bar:

- **Date range** — today, last 7 days, this month, last year, all time, and more (defaults to your configured `defaultDateRange`)
- **Site** — scope to a single site in a multisite install
- **Provider** — a single provider
- **Sender ID** — a single sender ID
- **Language** — a single message language

## Source plugin tracking

When a message is sent with a source plugin handle, SMS Manager records it on the message. The Analytics screen doesn't break down by source, but [SMS Logs](sms-logs.md) shows the source per message and lets you filter by it — so you can tell whether messages came from a form integration, a custom plugin, or a direct send. Pass the source when sending; see [Sending SMS](../developers/sending-sms.md).

## Exporting

Click **Export** to download the current view as CSV, JSON, or Excel (whichever formats are enabled in [Configuration](../get-started/configuration.md#date-time-and-export-formatting)). Exporting requires the **Export analytics** permission.

## Turning analytics on or off

Analytics is on by default and controlled by `enableAnalytics`. When off, no analytics events are recorded, the section is hidden, and future recurring analytics cleanup is cancelled.

When analytics is enabled and `analyticsRetention` is greater than `0`, SMS Manager maintains one daily analytics-cleanup schedule. Each run first removes records older than the retention period. If `autoTrimAnalytics` is enabled, it then removes the oldest remaining records until the total is within `analyticsLimit`. Setting retention to `0` keeps records indefinitely and cancels future recurring analytics cleanup; the count limit is not applied by that recurring family while retention is `0`.

On queue backends that limit individual delays, SMS Manager reaches the same daily Craft-timezone target through bounded handoffs; the handoffs do not run cleanup. Native and other queue backends retain the complete delay. To wipe everything immediately, use **Utilities → SMS Manager → Clear all analytics**. See [Configuration](../get-started/configuration.md#analytics).

## Next steps

- [SMS logs](sms-logs.md) — the per-message delivery history behind these numbers
- [Sending SMS](../developers/sending-sms.md) — attribute messages with a source plugin
