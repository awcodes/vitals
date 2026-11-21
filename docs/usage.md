---
title: Usage
description: Read the Vitals page, understand each monitor state, and add, pause, check and inspect monitors.
---

# Usage

## The page

The Vitals page lists every monitor in one table, ordered so the sites that need attention come first.

- **Header:** how many sites are monitored and when the most recent check ran, with **Run uptime checks** and **Add monitor**.
- **Summary strip:** counts for All, Healthy, Down or degraded, Unknown and Paused. Each count filters the table; click it again to clear the filter. **SSL issues** on the right is a separate filter, so certificate problems never mix with availability.
- **Needs attention:** up to five short messages, most urgent first, such as "Down · 4 failed checks in a row" or "SSL certificate expires in 6 days". Each opens that monitor's slide-over. When more monitors need attention, **View all** filters the table to them. The list is hidden when nothing is actionable.
- **Table:** the site name and host, status, 30-day uptime, a history strip, the latest response time, the certificate and when the site was last checked. On narrow screens the history, response and last-checked columns are hidden.

The default order is down, degraded, certificate problems by severity, never checked or stale, healthy, then paused, and by name within each.

## Monitor states

Availability and certificate health are separate, so an expired certificate never makes a responding site look down.

| Status | When |
| --- | --- |
| Healthy | A recent check passed and the response was not slow. |
| Degraded | The latest check failed but the outage is not confirmed yet, or the latest response was slower than `vitals.slow_response_ms`. |
| Down | The site has failed as many checks in a row as Spatie needs before it notifies. |
| Unknown | The site has never been checked ("Never checked"), or its last check is older than `vitals.stale_after_minutes` ("Stale"). Missing or old results never show as healthy. |
| Paused | Monitoring is paused, or uptime checks are turned off for the monitor. |

| Certificate | When |
| --- | --- |
| Valid | The certificate is valid and not close to expiry. |
| Expiring soon | It expires within Spatie's expiring-soon window. |
| Invalid | Validation failed, for example a hostname mismatch, or the stored expiry date has passed. |
| Check failed | The certificate could not be retrieved at all, so its expiry is unknown. This is not reported as expired. |
| Not checked | No certificate check has run yet. |
| Off | Certificate checks are turned off, for example for a plain-HTTP site. |

## The history strip

Each segment of the strip is one day:

- **Green:** every check passed at normal speed.
- **Muted amber:** every check passed, but the day's average response was slow.
- **Amber:** some checks failed, without a confirmed outage.
- **Red:** a confirmed outage overlapped the day.
- **Gray:** no checks were recorded, for example because the site was paused or not yet monitored.

Uptime is the share of recorded checks that passed in the window. Days without checks are not counted as success.

## Inspecting a monitor

The magnifying-glass action on a row, or an entry under **Needs attention**, opens a slide-over with four sections:

1. **Current health:** the availability and certificate conditions, a plain-language summary, the last check and last successful check, any ongoing outage, and the latest error with its raw text.
2. **Performance and uptime:** uptime, the latest response (or "Failed" with the last successful response), the average response of successful checks, a chart of daily average response times over the history window, and the latest 24 checks.
3. **SSL certificate:** the certificate's condition, expiry date and issuer, with validation details when there is a problem.
4. **Recent incidents:** the ten most recent incidents, ongoing first, each with a readable reason, start and end times and duration.

![The inspection slide-over for a site with an ongoing outage](assets/inspect-light.png#gh-light-mode-only)
![The inspection slide-over for a site with an ongoing outage](assets/inspect-dark.png#gh-dark-mode-only)

## Actions

- **Add monitor:** a site name, its URL and an optional server URL. Vitals checks the site straight away. Certificate checks are turned on for `https://` URLs. A URL that is already monitored, or that points at a private network address, is refused.
- **Check now:** runs an uptime check, and a certificate check if it is on, for one site.
- **Edit:** changes the name, URL, server URL and whether the certificate is checked. Changing the URL checks the site again.
- **Pause monitoring:** an optional reason and an optional time to resume automatically. See [Pausing](#pausing).
- **Resume monitoring:** resumes a paused site and checks it straight away.
- **Open server:** opens the monitor's server URL in a new tab, when one is set.
- **Delete:** removes the monitor with its checks, incidents and summaries.
- **Run uptime checks:** runs `monitor:check-uptime --force` for every monitor in one concurrent pass.

> [!WARNING]
> **Run uptime checks**, **Check now** and **Add monitor** run the checks inside the web request, so they take as long as the slowest site. With many sites, or slow ones, rely on the scheduled checks instead.

[Authorization](authorization.md) explains how to limit who can use each action.

## Pausing

Pause a monitor for planned maintenance or a site that is intentionally offline.

- No uptime or certificate checks run while a monitor is paused, so no notifications are sent. This also covers `monitor:check-uptime --force`.
- Any open incident is closed at the moment of pausing.
- The monitor's own check settings are left alone, so resuming restores them.
- Resuming clears Spatie's failure streak. A site that is still down needs the usual consecutive failures before anyone is notified, and a site that came back during the pause sends no recovery notification.
- A pause with a resume time ends automatically through `monitors:resume-expired-pauses`, which [Scheduling](scheduling.md) runs every five minutes.

Paused monitors sort to the bottom of the table, show their reason and end time under the status, and are left out of **Needs attention**, the down and SSL counts and the alerts widget.

## Notifications

Notifications are Spatie's, sent through the channels in `config/uptime-monitor.php`. With `resend_uptime_check_failed_notification_every_minutes` set to `0`, as [Installation](installation.md#point-spatie-at-vitals) recommends, a site sends one notification when its outage is confirmed and one when it recovers. Certificate notifications follow Spatie's behaviour unchanged.

## The alerts widget

The plugin adds a **Monitor Alerts** widget to the panel's dashboard. It lists sites whose uptime check is failing or whose certificate is invalid, with the failure reason, and links to the Vitals page. Paused sites and checks that are turned off are left out. Turn it off with `VitalsPlugin::make()->alertsWidget(false)`.

![The Monitor Alerts widget listing failing sites](assets/alerts-widget-light.png#gh-light-mode-only)
![The Monitor Alerts widget listing failing sites](assets/alerts-widget-dark.png#gh-dark-mode-only)

## Demo data

`VitalsDemoSeeder` creates eighteen fictional monitors on `.test` domains with 30 days of history, covering every state above. It only runs in the `local` environment, sends no notifications and makes no requests. Running it again replaces its own monitors and leaves the rest alone.

```bash
php artisan db:seed --class="Awcodes\Vitals\Database\Seeders\VitalsDemoSeeder"
```
