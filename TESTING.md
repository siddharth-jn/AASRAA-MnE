# Testing

Tests use SQLite in memory and do not modify the MySQL development database.

```bash
php artisan test --compact
```

`AggregationTest` verifies additive totals, latest snapshots, cross-centre sums, combined ratios, and missing values. `ImpactTest` verifies authentication redirects, centre scope isolation, and self approval prevention. Production workflows should add browser tests, upload authorization cases, revision approval cases, and import repeat-safety as the corresponding phases are completed.

The frontend production bundle is checked with `npm run build`. PHP style is normalized with `vendor/bin/pint --format agent`.
