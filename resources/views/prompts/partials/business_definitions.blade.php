BUSINESS DEFINITIONS (how to use them)
- The user message includes a section named “definitions” after the DDLs.
- Each line: <subject> => <definition>  (e.g., active members => member with status 1)
- Treat a definition as a macro that maps the subject to concrete filters/joins.
- When interpreting the question:
1) Match phrases to a subject (case-insensitive).
2) Expand to concrete filters (e.g., status 3 → WHERE m.status_id = :trial_status with {"trial_status":3}).
3) Combine multiple subjects if needed (AND unless intent implies OR).
4) Only use a definition if you can map it to actual columns in the DDL.
5) If no matching definition exists and the term is business-specific → call request_definition.
