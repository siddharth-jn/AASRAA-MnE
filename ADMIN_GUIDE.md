# Administrator guide

Open `/admin` and sign in with an ADMIN account.

Create a programme first, then create its project and select its reporting centres. Centres store a public name, type, broad region, capacity when relevant, and provenance note. Avoid exact addresses or coordinates unless Aasraa authorizes their operational use.

Create an indicator from **Indicator Definitions**:

1. In **Basic details**, select the project and enter a permanent unique code, name, definition, category, unit, frequency, and effective date.
2. In **Input and validation**, choose the generated input type. Configure numeric ranges, date limits, text length, accepted dropdown options, or numerator and denominator labels as appropriate.
3. In **Centre assignment**, select one or more centres already attached to the project.
4. In **Aggregation and targets**, choose separate rules for aggregation over time and across centres. Enable target tracking and select the performance direction when targets are meaningful.
5. In **Evidence and approval**, configure proof formats, file limits, evidence review, and approval requirements.
6. Save the definition and review its generated control in **Data Entry**.

Open **Indicator Targets** to add or revise targets. Select an indicator-centre assignment, choose monthly, fiscal quarterly, half-yearly, or annual, and enter a numeric target, acceptable range, or expected status. The chosen date is normalized to the correct period based on the April–March financial year. A duplicate target for the same assignment and period is rejected. Deactivate superseded targets rather than deleting them; edits are recorded in the audit log.

Set `active` off to archive a project, centre, indicator, or target. The admin interface intentionally omits destructive record actions for historically referenced configuration. Definition changes generate version snapshots so previously submitted records keep their original rules.

Create staff in **Users**, assign one role, then select the projects and centres they may access. Password fields are required when creating a user and ignored when left blank during editing.
