# Demo data

All seeded users, programme records, assignments, indicators, targets, entries, approvals, and evidence carry `is_demo=true`. The UI displays **DEMO MODE** while demo users exist. Values are deterministic and are never represented as official results.

The 11 programme names and the first 18 location names come from Aasraa's public website. Higher Education uses Dehradun and Udham Singh Nagar as reporting regions, not claimed physical centres. Programme-centre mappings, capacities, staff assignments, targets, monthly achievements, statuses, and evidence are synthetic configurations.

Seed coverage includes April through September 2026, 66 indicators, achieved and missed targets, missing entries, pending decisions, returned and rejected records, ratios, snapshots, flows, and synthetic text evidence.

Demo accounts use these emails: `admin@aasra.demo`, `senior@aasra.demo`, `lead@aasra.demo`, `coordinator@aasra.demo`, and `viewer@aasra.demo`. Their shared development password is the private `DEMO_PASSWORD` in `.env`; it is not stored in Git or in this document.

The seeder uses stable keys and `updateOrCreate`, so reruns do not duplicate records. Clear demo data with:

```bash
php artisan impact:clear-demo-data
```

The command deletes only rows explicitly tagged as demo plus their private demo evidence. It does not select operational records.

Public context: [Aasraa homepage](https://aasraatrust.org/), [Street Smart](https://aasraatrust.org/programmes/street-smart/), [Girls' Education](https://aasraatrust.org/programmes/girls-education/), [Higher Education](https://aasraatrust.org/programmes/higher-education-program/), and [SkillAbility](https://aasraatrust.org/programmes/skillability/).
