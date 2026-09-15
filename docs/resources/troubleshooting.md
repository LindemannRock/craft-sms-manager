# Troubleshooting

Common issues and how to resolve them. For failed sends, check **SMS Manager → Logs → SMS** for a safe failure category, optional HTTP status, and correlation reference. **SMS Manager → Logs → System** carries the matching reference without storing the full recipient or message. See [Logging](logging.md) for log levels, files, and access permissions.

## Messages aren't sending

Work through these in order:

1. **Provider enabled?** **SMS Manager → Providers** — the provider must be enabled. A disabled provider returns a "Provider is disabled" error.
2. **Sender ID enabled?** **SMS Manager → Sender IDs** — the sender must be enabled too.
3. **Credentials correct?** Re-check the provider's API key (MPP-SMS) or Account SID and Auth Token (Twilio). If you used an environment variable, confirm it's set.
4. **Check the SMS log.** Match the provider, category, status, and correlation reference to the failed attempt.
5. **Check system logs.** **SMS Manager → Logs → System** carries the same failure reference plus an irreversible recipient reference.
6. **Check the provider dashboard.** Raw gateway error text is deliberately not copied into Craft because it can contain credentials, request URLs, or message data. Use the time and correlation details to compare the attempt with the provider's own dashboard.

## "No provider configured" or "No sender ID configured"

A send that relies on the default provider or sender failed to resolve one. This is intentional fail-loud behavior — SMS Manager won't silently substitute a different provider or sender.

- Set a **Default Provider** and **Default Sender ID** under **SMS Manager → Settings → General**.
- If a default *is* set, confirm the configured handle resolves to an **enabled** record. A typo, a deleted record, or a disabled one all cause this error.

## Arabic messages display incorrectly

- Pass `'ar'` as the language when sending so language-aware providers receive the correct metadata.
- SMS Manager's analytics and Test SMS calculator classify the message content itself as GSM-7 or UCS-2; changing only the language does not change that classification.
- MPP-SMS still uses the language for its existing gateway-specific wire parameter and UCS-2 conversion. Twilio determines its own transport encoding from the submitted content.
- Confirm the recipient's device supports Arabic SMS.

## Analytics isn't tracking

- Check **`enableAnalytics`** is on under **SMS Manager → Settings → Analytics**.
- Analytics is only recorded for messages sent through the service — direct gateway calls outside SMS Manager won't appear.

## A recipient number is rejected

If the provider has an **Allowed countries** list, numbers outside it are rejected at send time with the `invalid-recipient` category. Either add the country to the provider, or send through a provider that allows it. MPP-SMS also validates number length for the supported GCC/MENA countries.

## Scheduled cleanup jobs are missing

SMS Manager schedules daily cleanup jobs for analytics and SMS logs. If one isn't running:

- Confirm the queue worker is running.
- Visit any Control Panel page to let SMS Manager bootstrap the initial cleanup jobs.
- Check `analyticsRetention` is greater than `0` for analytics cleanup.
- Check `smsLogsRetention` is greater than `0` for logs cleanup.
- Check `enableAnalytics` / `enableSmsLogs` is on for the relevant job.

Each cleanup family is independent. Saving settings or handling a normal web request reconciles the analytics and log schedules independently; console and migration bootstrap do not create or cancel cleanup rows. Disabling a family or setting its retention to `0` cancels future recurring cleanup for that family.

If a cleanup occurrence is already holding one family's scheduling lock, bootstrap skips that family immediately, writes an `sms-manager` warning, and still attempts the other family. It does not inspect or change the busy family's queue rows. A later normal web request retries the skipped reconciliation, including cancellation for a family that has since been disabled.

During bootstrap, SMS Manager recognizes its earlier recurring cleanup rows, keeps the earliest healthy row, and removes only true duplicates from the same family. Failed rows do not block recovery, and unrelated or one-shot queue jobs are left alone. If duplicates keep returning after a deployment, confirm all web workers are running the same plugin version and old queue workers have been restarted.

On a queue backend that limits individual delays, a daily schedule may appear as a sequence of bounded handoff jobs before the final cleanup job. Those intermediate handoffs do not delete analytics or logs. Native and other queue backends keep the full delay. This behavior is backend portability support; it does not indicate that a particular hosted environment has been validated.

Craft stores queue job descriptions when rows are queued, so date/time format changes apply to newly queued rows. Existing delayed rows keep their old label until they run or are requeued. Queue labels stay compact: numeric months render numerically, while short and long month settings both render as short month names.

## Saving settings shows a validation error

Numeric settings (analytics limit, logs limit, retention periods, items per page) must be whole numbers within their allowed range. An invalid value keeps you on the same page with the error shown inline.

When a setting is overridden in `config/sms-manager.php`, the Control Panel field is skipped on save — change the config file value instead.

## Provider failure categories

| Category | What to check |
|----------|---------------|
| `configuration` | Required credentials are missing from the provider settings or environment |
| `endpoint-policy` | The configured endpoint conflicts with the outbound request security policy |
| `invalid-recipient` | Recipient format, length, or Allowed Countries settings |
| `transport` | Connectivity, timeout, DNS, TLS, and provider availability |
| `http` | The HTTP status, credentials, account state, and provider dashboard |
| `provider` / `provider-response` | The custom or built-in provider rejected the request; check its dashboard |
| `provider-exception` | Provider code failed outside a recognized transport exception; check plugin compatibility and the provider implementation |
| `malformed-response` | The gateway returned an empty or unusable success response |

Every provider failure includes a correlation reference. Include it, along with the provider, category, status, and approximate time, in support requests. Do not paste API keys, authorization headers, request URLs, recipients, or message text into tickets.
