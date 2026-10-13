# Database schema

The schema is created by `database/migrations/2026_10_13_090128_create_impact_tables.php`.

| Area | Tables | Purpose |
|---|---|---|
| Identity | `users`, `project_user_assignments`, `centre_user_assignments` | Roles and project or centre scope |
| Portfolio | `programmes`, `projects`, `centres`, `project_centres` | Programme hierarchy and reusable reporting locations |
| Indicators | `indicator_definitions`, `indicator_definition_versions`, `indicator_assignments`, `indicator_targets` | Typed, versioned KPI configuration and targets |
| Reporting | `indicator_entries`, `entry_revisions` | Period records, workflow state, and history |
| Evidence and review | `evidence_files`, `approval_actions`, `notifications` | Private proof, decisions, and in-app events |
| Migration | `import_batches`, `import_rows` | Repeat-safe spreadsheet staging |
| Audit | `audit_logs` | Administrative model changes |

Foreign keys prevent removal of referenced historical data. Unique constraints prevent duplicate project-centre links, indicator-centre assignments, targets, reporting records, and revision numbers. Date and workflow columns are indexed for dashboard and report filters. Projects and centres use soft deletion; historically used definitions are archived with `active=false`.
