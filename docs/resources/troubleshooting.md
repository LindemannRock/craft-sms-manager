# Troubleshooting

Common issues and how to resolve them. For detailed errors, check **SMS Manager → SMS Logs** (per-message provider responses) and **SMS Manager → System Logs** (plugin-level logging).

## Messages aren't sending

Work through these in order:

1. **Provider enabled?** **SMS Manager → Providers** — the provider must be enabled. A disabled provider returns a "Provider is disabled" error.
2. **Sender ID enabled?** **SMS Manager → Sender IDs** — the sender must be enabled too.
3. **Credentials correct?** Re-check the provider's API key (MPP-SMS) or Account SID and Auth Token (Twilio). If you used an environment variable, confirm it's set.
4. **Check the SMS log.** Each failed message stores the provider's error and raw response — that usually names the cause.
5. **Check system logs.** **SMS Manager → System Logs** for plugin-level detail.

## "No provider configured" or "No sender ID configured"

A send that relies on the default provider or sender failed to resolve one. This is intentional fail-loud behavior — SMS Manager won't silently substitute a different provider or sender.

- Set a **Default Provider** and **Default Sender ID** under **SMS Manager → Settings → General**.
- If a default *is* set, confirm the configured handle resolves to an **enabled** record. A typo, a deleted record, or a disabled one all cause this error.

## Arabic messages display incorrectly

- Pass `'ar'` as the language when sending — encoding is chosen from the language.
- MPP-SMS encodes Arabic as UCS-2 automatically; Twilio auto-detects and sends non-Latin text as UCS-2.
- Confirm the recipient's device supports Arabic SMS.

## Analytics isn't tracking

- Check **`enableAnalytics`** is on under **SMS Manager → Settings → Analytics**.
- Analytics is only recorded for messages sent through the service — direct gateway calls outside SMS Manager won't appear.

## A recipient number is rejected

If the provider has an **Allowed countries** list, numbers outside it are rejected at send time with a clear error. Either add the country to the provider, or send through a provider that allows it. MPP-SMS also validates number length for the supported GCC/MENA countries.

## Scheduled cleanup jobs are missing

SMS Manager schedules daily cleanup jobs for analytics and SMS logs. If one isn't running:

- Confirm the queue worker is running.
- Visit any Control Panel page to let SMS Manager bootstrap the initial cleanup jobs.
- Check `analyticsRetention` is greater than `0` for analytics cleanup.
- Check `smsLogsRetention` is greater than `0` for logs cleanup.
- Check `enableAnalytics` / `enableSmsLogs` is on for the relevant job.

During bootstrap, SMS Manager collapses duplicate pending cleanup rows automatically and keeps one row for the next daily cleanup run. If duplicates keep returning after a deployment, confirm all web workers are running the same plugin version and old queue workers have been restarted.

Craft stores queue job descriptions when rows are queued, so date/time format changes apply to newly queued rows. Existing delayed rows keep their old label until they run or are requeued. Queue labels stay compact: numeric months render numerically, while short and long month settings both render as short month names.

## Saving settings shows a validation error

Numeric settings (analytics limit, logs limit, retention periods, items per page) must be whole numbers within their allowed range. An invalid value keeps you on the same page with the error shown inline.

When a setting is overridden in `config/sms-manager.php`, the Control Panel field is skipped on save — change the config file value instead.

## Common MPP-SMS provider errors

| Error | Cause |
|-------|-------|
| Invalid API Key | Wrong key in the provider settings |
| Invalid Sender ID | Sender ID not registered with the provider |
| Invalid Mobile Number | Number format the gateway can't accept |
| Insufficient Balance | Top up your provider account |
