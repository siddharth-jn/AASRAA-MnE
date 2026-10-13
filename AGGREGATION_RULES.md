# Aggregation rules

Time and centre aggregation are configured separately.

| Rule | Meaning |
|---|---|
| SUM | Add flow values such as sessions or meals |
| LATEST SNAPSHOT | Use the last value in a period, such as current enrolment |
| AVERAGE | Arithmetic mean when observations are comparable |
| WEIGHTED AVERAGE | Sum value × weight, divided by total weight |
| RATIO | Sum numerators divided by summed denominators × 100 |
| STATUS | Count each categorical status |
| UNIQUE COUNT | Requires deduplication identifiers; returns no aggregate when unavailable |
| NON-AGGREGATABLE | Show individual records without a misleading combined value |

Percentages are never summed. Snapshots are never summed across time. A ratio such as 85/100 and 8/10 becomes 93/110, or 84.55%, rather than averaging 85% and 80%. Empty sets return missing data, not zero. Reports only include approved revisions and do not prorate monthly values for partial months.
