export const SETTING_TYPES = {
    max_rows_inline_csv: {
        type: 'numeric',
        label: 'Max Rows Inline CSV',
        description: 'Maximum number of rows for synchronous CSV export. Exports exceeding this threshold are processed asynchronously.',
    },
    max_priority_tables: {
        type: 'numeric',
        label: 'Max Priority Tables',
        description: 'Maximum number of tables that can be marked as priority.',
    },
    llm_resume_max_steps: {
        type: 'numeric',
        label: 'LLM Resume Max Steps',
        description: 'Maximum number of resume steps allowed while the model continues a tool-driven response.',
    },
    start_of_week: {
        type: 'select',
        label: 'Start of Week',
        description: 'Controls which weekday is treated as the first day of the week in prompt date reasoning.',
        options: [
            { value: 'monday', label: 'Monday' },
            { value: 'tuesday', label: 'Tuesday' },
            { value: 'wednesday', label: 'Wednesday' },
            { value: 'thursday', label: 'Thursday' },
            { value: 'friday', label: 'Friday' },
            { value: 'saturday', label: 'Saturday' },
            { value: 'sunday', label: 'Sunday' },
        ],
    },
    week_definition: {
        type: 'select',
        label: 'Week Definition',
        description: 'Controls whether week boundaries follow ISO weeks (Mon-Sun) or US weeks (Sun-Sat).',
        options: [
            { value: 'iso', label: 'ISO (Mon-Sun)' },
            { value: 'us', label: 'US (Sun-Sat)' },
        ],
    },
    max_tokens: {
        type: 'numeric',
        label: 'Max Tokens',
        description: 'Maximum token budget made available to the model.',
    },
};
