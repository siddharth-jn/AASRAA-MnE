# Testing

Tests use SQLite in memory and do not modify the MySQL development database.

```bash
php artisan test --compact
```

`AggregationTest` verifies additive totals, latest snapshots, cross-centre sums, combined ratios, and missing values. Feature tests cover authentication and password recovery, centre scope isolation, indicator configuration, typed validation, evidence authorization, submission and review notifications, evidence review confirmation, revision approval behavior, reporting periods, completeness, deadline reminders, approved-only consolidated reporting, CSV/XLSX responses, spreadsheet mapping, row validation, duplicate detection, draft-only import, and template downloads.

Phase 6 should add browser-level journeys, production security checks, representative database performance tests, and deployment smoke tests on the selected hosting environment.

The frontend production bundle is checked with `npm run build`. PHP style is normalized with `vendor/bin/pint --format agent`.
