# Dashboard widgets

Keep SMS delivery activity visible on Craft's main dashboard without opening SMS Manager. Two optional widgets summarize the delivery-log data each user is allowed to see.

## What you'll use them for

- Watching sent and failed message totals over a chosen date range
- Keeping the latest delivery statuses visible during day-to-day work
- Scoping a widget to one editable site or one stored message language
- Jumping from a dashboard summary to the full Analytics or SMS logs screen

## Add a widget

In Craft's main dashboard:

1. Click **New widget**.
2. Choose **SMS Manager - Analytics** or **SMS Manager - Recent SMS**.
3. Set its filters, then save the widget.

Both widgets are available only when SMS delivery logs are enabled and the current user has the **View SMS logs** permission. If either condition later changes, an existing widget shows the empty state instead of exposing log data.

## Analytics widget @since(5.14.0)

The analytics widget displays a sent-versus-failed doughnut chart, total logged messages, and the success rate for its selected range. Its title links through to the full Analytics screen.

| Setting | Default | Options |
|---------|---------|---------|
| Date range | Last 7 days | Any date range offered by SMS Manager |
| Site | All sites | All editable sites or one editable site |
| Language | All languages | Languages from editable sites and stored SMS logs |

**All sites** includes rows for the user's editable sites plus global rows whose site is not recorded. The success rate is sent messages divided by all messages in the filtered range, so pending or other stored statuses remain part of the total.

## Recent SMS widget @since(5.14.0)

The recent widget lists the latest recipients, message previews, sender names, and statuses. Each item links to **SMS Manager → Logs → SMS** for the complete delivery record.

| Setting | Default | Options |
|---------|---------|---------|
| Messages | 5 | 3, 5, 10, 15, or 20 |
| Site | All sites | All editable sites or one editable site |
| Language | All languages | Languages from editable sites and stored SMS logs |

Message previews are shortened to 80 characters. Sender names are resolved from the current database or config-backed sender when possible; a deleted sender can therefore appear without a name while the stored delivery row remains available.

## Next steps

- [Analytics](analytics.md) — explore the full reporting and export tools
- [SMS logs](sms-logs.md) — search, inspect, export, or delete individual delivery records
- [Permissions](../developers/permissions.md) — control who can view the underlying log data
