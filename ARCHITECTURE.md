# Architecture

AASRA IMPACT is a modular Laravel 13 monolith. Blade and Tailwind render the staff portal. Alpine.js handles the responsive navigation. Filament 5 and Livewire 4 provide the administration panel. MySQL is the authoritative store; private evidence uses Laravel's local private disk.

Requests pass through Laravel authentication, gates, and `ScopeService`. Every project, centre, assignment, and entry query is reduced to the current user's assignments. `EntryService` owns transactional draft, revision, submission, and review changes. `AggregationService` applies each indicator's configured time and centre rules. `ReportingPeriodService` normalizes fiscal and custom periods, `CompletenessService` creates expected reporting obligations, and `ReportingService` reads approved revisions only.

Indicator configuration is data driven. A definition declares its type, validation range, unit, time aggregation, centre aggregation, evidence policy, effective date, and centre assignments. Saved definitions create immutable definition snapshots for revisions. Submitted achievements live in versioned `entry_revisions`; `indicator_entries` points to both the latest revision and the approved revision.

`ImportService` stages source rows before changing reporting data. It parses CSV/XLSX files, maps columns, resolves scoped centre and indicator references, validates typed values, detects duplicate keys, and creates draft revisions transactionally after confirmation.

Evidence paths are never exposed as public storage URLs. Previews and downloads pass through a controller and an authorization gate. Database notifications support workflow events without a queue worker; email is an optional second channel. Configuration uses environment variables and secrets are excluded from Git.
