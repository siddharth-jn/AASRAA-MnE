# Demo data

The showcase uses the existing authoritative programme and centre catalogue. It does not create or replace programmes, projects, or centres. Synthetic users, assignments, indicators, targets, entries, approvals, evidence, notifications, and audit records carry `is_demo=true`. The UI displays **DEMO MODE** while demo users exist, and synthetic values are never represented as official results.

For the current seven programme aggregates, the showcase creates 14 indicators: one monthly reporting example per programme and seven focused workflow examples. Together they cover every supported input type, monthly, quarterly, half-yearly, and annual reporting, numeric, range, and status targets, all supported aggregation methods, not-applicable values, pending decisions, corrections, rejection, final approval, and eight synthetic evidence formats.

Six workflow accounts are created for Assistant Programme Lead, Programme Lead, Senior Programme Lead, M&E Head, CEO, and CFO roles. Their shared development password is the private `DEMO_PASSWORD` value in `.env`; it is not stored in Git or in this document.

Create or reset the local showcase for a specific year with:

```bash
php artisan impact:seed-showcase-data --year=2026
```

The command clears and recreates only demo-tagged workflow data. It preserves the authoritative catalogue and operational indicators, targets, and entries. Clear demo data with:

```bash
php artisan impact:clear-demo-data
```

The clear command deletes only rows explicitly tagged as demo plus their private demo evidence. It does not select operational records.

Public context: [Aasraa homepage](https://aasraatrust.org/), [Street Smart](https://aasraatrust.org/programmes/street-smart/), [Girls' Education](https://aasraatrust.org/programmes/girls-education/), [Higher Education](https://aasraatrust.org/programmes/higher-education-program/), and [SkillAbility](https://aasraatrust.org/programmes/skillability/).
