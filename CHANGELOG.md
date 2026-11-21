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
