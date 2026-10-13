<?php

return [
    'demo_password' => env('DEMO_PASSWORD'),
    'financial_year_start_month' => 4,
    'roles' => ['ADMIN', 'SENIOR PROGRAM LEAD', 'PROGRAM LEAD', 'CENTRE COORDINATOR', 'VIEWER'],
    'location_types' => ['Education Centre', 'Partner School', 'Mobile Learning Unit', 'Shelter Home', 'Training Centre', 'Community Location', 'Programme Reporting Region'],
    'categories' => ['Input', 'Activity', 'Output', 'Outcome', 'KPI', 'Milestone', 'Qualitative Indicator'],
    'data_types' => ['integer', 'decimal', 'percentage', 'ratio', 'boolean', 'date', 'text', 'long_text', 'single_select', 'multi_select', 'status'],
    'aggregations' => ['SUM', 'LATEST SNAPSHOT', 'AVERAGE', 'WEIGHTED AVERAGE', 'RATIO', 'UNIQUE COUNT', 'STATUS', 'NON-AGGREGATABLE'],
];
