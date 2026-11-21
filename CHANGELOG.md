# Changelog

All notable changes to `awcodes/vitals` are documented in this file.

## Unreleased

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
