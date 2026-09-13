# Analytics

Analytics tells you how much SMS you're sending, how much is succeeding, and where it's coming from — without digging through individual logs.

Every message SMS Manager sends is recorded as an analytics event (when analytics is enabled). The Analytics screen aggregates those events into totals, a success rate, a language and encoding breakdown, and per-provider, per–sender ID, and per-site views.

## What you'll use it for

- Checking sent vs failed totals and the success rate over a period
- Comparing the independent language and content-derived encoding breakdowns
- Reviewing Unicode character totals and billable SMS segment totals
- Comparing how different providers and sender IDs are performing
- Reporting per site in a multisite install

## Viewing analytics

Go to **SMS Manager → Analytics**. The screen shows:

- **Summary** — total sent and failed, plus the success rate (sent ÷ sent + failed)
- **Daily trend** — sent and failed per day across the range
- **Language breakdown** — messages grouped by their recorded language
- **Encoding breakdown** — GSM-7, UCS-2, and unknown historical events, plus character and SMS segment totals
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
- **Source** — a source plugin, or direct sends

## Encoding, characters, and segments

Encoding is calculated from message content, not from language, locale, text direction, or RTL status. A GSM-7 message can use 160 septets in one segment or 153 septets per multipart segment; GSM extension-table characters consume two septets. A UCS-2 message can use 70 UTF-16 code units in one segment or 67 units per multipart segment; emoji and other astral characters consume two UTF-16 code units.

The **Characters** total counts Unicode code points. **SMS segments** is the number of calculated wire-size segments, and is the meaning of the exported `SMS Segments` column. An empty message has zero characters and zero segments. Language remains a separate grouping and filter, so identical content has the same encoding and segment count under every language selection.

Older rows created before content facts were recorded remain visible as **Unknown** encoding. Their character and segment values are also **Unknown**, not zero. When a filtered result includes any such history, aggregate character or segment totals show **Unknown** rather than a misleading partial sum.

## Source plugin tracking

When a message is sent with a source plugin handle, SMS Manager records it on the event. Use the **Source** filter to isolate a plugin or direct sends. [SMS Logs](sms-logs.md) also shows and filters the source per message. Pass the source when sending; see [Sending SMS](../developers/sending-sms.md).

## Exporting

Click **Export** to download the current filtered view as CSV, JSON, or Excel (whichever formats are enabled in [Configuration](../get-started/configuration.md#date-time-and-export-formatting)). Exports include language, content-derived encoding, source, Unicode character count, and SMS segment count. Historical unavailable facts are written as **Unknown**. Exporting requires the **Export analytics** permission.

## Turning analytics on or off

Analytics is on by default and controlled by `enableAnalytics`. When off, no analytics events are recorded, the section is hidden, and future recurring analytics cleanup is cancelled.

When analytics is enabled and `analyticsRetention` is greater than `0`, SMS Manager maintains one daily analytics-cleanup schedule. Each run first removes records older than the retention period. If `autoTrimAnalytics` is enabled, it then removes the oldest remaining records until the total is within `analyticsLimit`. Setting retention to `0` keeps records indefinitely and cancels future recurring analytics cleanup; the count limit is not applied by that recurring family while retention is `0`.

On queue backends that limit individual delays, SMS Manager reaches the same daily Craft-timezone target through bounded handoffs; the handoffs do not run cleanup. Native and other queue backends retain the complete delay. To wipe everything immediately, use **Utilities → SMS Manager → Clear all analytics**. See [Configuration](../get-started/configuration.md#analytics).

## Next steps

- [SMS logs](sms-logs.md) — the per-message delivery history behind these numbers
- [Sending SMS](../developers/sending-sms.md) — attribute messages with a source plugin
