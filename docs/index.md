---
title: Vitals
description: Website uptime, certificate and incident monitoring for Filament panels, built on spatie/laravel-uptime-monitor.
---

# Vitals

Vitals adds a website monitoring page to a Filament panel. It shows which of your sites are up, which are slow or failing, which SSL certificates need attention, and what happened over the last 30 days.

![The Vitals page with a summary strip, a needs-attention list and the monitor table](assets/page-light.png#gh-light-mode-only)
![The Vitals page with a summary strip, a needs-attention list and the monitor table](assets/page-dark.png#gh-dark-mode-only)

## How it works

Vitals does not run its own checks. [spatie/laravel-uptime-monitor](https://github.com/spatie/laravel-uptime-monitor) stays the check engine: it requests each site, inspects certificates, counts consecutive failures and sends notifications. Vitals hooks into the monitor model Spatie already loads and adds what Spatie does not store:

- **Check history:** one row per uptime check, with the HTTP status and response time.
- **Incidents:** a confirmed outage opens an incident, and the first passing check closes it.
- **Daily summaries:** completed days are summarised before old checks are pruned, so long-term history survives.
- **Pausing:** a monitor can be paused for planned downtime, with an optional reason and an automatic resume time. Paused monitors run no checks and send no notifications.
- **Presentation:** the Vitals page, an inspection slide-over for each site, and a dashboard widget listing sites that are failing.
- **Guards:** monitor URLs and redirects that point at private network addresses are refused.

## Who it is for

Vitals suits teams that run a handful to a few hundred sites from one Laravel application and want their status inside the Filament panel they already use. It requires a Filament panel with a custom theme.

## Next steps

- [Installation](installation.md) walks through installing the package, wiring it into Spatie's config and registering the plugin.
- [Usage](usage.md) explains the page, the monitor states and the actions.
- [Authorization](authorization.md) covers the policy that controls who can do what.
- [Scheduling](scheduling.md) lists the commands your scheduler needs to run.
