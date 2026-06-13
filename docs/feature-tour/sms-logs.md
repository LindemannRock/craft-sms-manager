# SMS logs

SMS logs are the per-message record of everything SMS Manager has sent — who it went to, what it said, whether it succeeded, and exactly what the provider returned. When a message doesn't arrive, this is where you find out why.

## What you'll use it for

- Confirming a specific message was sent
- Diagnosing a failure from the provider's own response and error
- Searching for messages to a recipient or containing some text
- Exporting a delivery history for reporting or audit
- Tracing which plugin or feature triggered a message

## Viewing logs

Go to **SMS Manager → SMS Logs**. Each row shows the date, recipient, message, language, provider, sender ID, status, and source. Expand a row to see the full message, the raw provider response, the provider message ID, and any error.

![SMS logs](images/sms-logs-index.webp)

## Statuses

| Status | Meaning |
|--------|---------|
| **Pending** | The log row was created but the provider hasn't returned a result yet |
| **Sent** | The provider accepted the message |
| **Failed** | The provider rejected the message — see the error and response |

## Filtering and searching

- **Status** — all, sent, failed, or pending
- **Provider** — a single provider
- **Language** — a single message language
- **Source** — all, direct (no source plugin), or a specific source plugin
- **Date range** — today, last 7 days, this month, all time, and more
- **Search** — matches the recipient, message text, or provider message ID

Sort by date, recipient, status, language, or provider. The list paginates at your configured `itemsPerPage`, and refreshes automatically if you've set a dashboard refresh interval.

## Exporting

Click **Export** to download logs as CSV, JSON, or Excel (whichever formats are enabled). The export honors your current date range, or — if you've selected specific rows — exports just those. Columns: Date, Recipient, Message, Language, Status, Provider, Sender ID, Source, Message ID, Error, and Provider Response. Exporting requires the **Export SMS logs** permission.

## Deleting logs

With the **Delete SMS logs** permission you can delete a single log, delete selected logs in bulk, or clear everything at once. To wipe all logs from the maintenance tools instead, use **Utilities → SMS Manager → Clear all SMS logs**.

## Retention

Logging is on by default (`enableSmsLogs`). Old logs are trimmed automatically on a daily schedule, governed by `smsLogsLimit` and `smsLogsRetention`. Set retention to `0` to keep logs forever. See [Configuration](../get-started/configuration.md#sms-logs).

> [!NOTE]
> Logs identify providers and sender IDs even after the underlying record is deleted, and for config-only providers and senders, because each message stores a snapshot of the provider and sender handle at send time.

## Next steps

- [Analytics](analytics.md) — aggregated trends across all messages
- [Troubleshooting](../resources/troubleshooting.md) — common provider errors
