# Architecture

AASRA IMPACT is a modular Laravel 13 monolith. Blade and Tailwind render the staff portal. Alpine.js handles the responsive navigation. Filament 5 and Livewire 4 provide the administration panel. MySQL is the authoritative store; private evidence uses Laravel's local private disk.

Requests pass through Laravel authentication, gates, and `ScopeService`. Every project, centre, assignment, and entry query is reduced to the current user's assignments. `EntryService` owns transactional draft, revision, submission, and review changes. `AggregationService` applies each indicator's configured time rule. `ReportingService` reads approved revisions only.

Indicator configuration is data driven. A definition declares its type, validation range, unit, time aggregation, centre aggregation, evidence policy, effective date, and centre assignments. Saved definitions create immutable definition snapshots for revisions. Submitted achievements live in versioned `entry_revisions`; `indicator_entries` points to both the latest revision and the approved revision.

Evidence paths are never exposed as public storage URLs. Downloads pass through a controller and an authorization gate. Configuration uses environment variables and secrets are excluded from Git.
