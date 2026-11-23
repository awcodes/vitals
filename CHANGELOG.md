# Changelog

All notable changes to `awcodes/vitals` are documented in this file.

## 0.1.1 - 2026-11-23

From adopting Vitals in a second application:

- `VitalsPlugin::navigationIcon()` accepts an icon enum, or `null` to show no
  icon in a panel whose navigation carries none.
- `Vitals::schedule()` takes the `Schedule` as optional, so `routes/console.php`
  can call `Vitals::schedule()` without resolving one.
- Docs: scheduling from `routes/console.php`, where the stylesheet is published,
  and how the recommended notification setting changes what an adopting team
  receives.

## 0.1.0 - 2026-11-21

- Initial package scaffold: Workbench, agent tooling and CI.
- Monitoring domain: the `Monitor` model (extending Spatie's), check
  history, incidents and daily summaries, health and certificate states,
  the private-address and redirect guards, and retention.
- Commands `monitors:prune-history` and `monitors:resume-expired-pauses`.
- Idempotent migration stubs, published into the app.
- `MonitorPolicy`, registered for the `Monitor` model unless the app has its
  own; swap it with `vitals.policy`.
- `config/vitals.php`, including a display `timezone` setting.
- `VitalsDemoSeeder` for local development.
- The Vitals page: summary strip, attention list, monitor table with 30-day
  history, inspection slide-over, and add, edit, pause, resume, check and
  delete actions, each authorized through `MonitorPolicy`.
- `VitalsAlertsWidget` for the dashboard.
- `VitalsPlugin` with `navigationLabel()`, `navigationIcon()`,
  `navigationGroup()`, `navigationSort()`, `slug()`, `timezone()` and
  `alertsWidget()`.
- The `vitals-heartbeat` icon and a small stylesheet registered as a Filament
  asset.
- `vitals:install`, which also publishes the uptime-monitor config and
  migration when the app has not yet, and prints the settings to change.
- `vitals:doctor`, a read-only check of the uptime-monitor settings, tables and
  panel themes.
- `Vitals::schedule()`, which registers the four scheduled commands.
- Documentation in `docs/`.
- `Monitor` and `TimedUptimeCheckRequest` can be extended, for apps that already
  customise Spatie's monitor model or request action.
- `vitals.allow_private_addresses` lets apps that monitor internal services
  turn off the private-address guards.
