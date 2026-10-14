<?php

return [
    'demo_password' => env('DEMO_PASSWORD'),
    'email_notifications' => env('EMAIL_NOTIFICATIONS', false),
    'reporting_deadline_day' => env('REPORTING_DEADLINE_DAY', 5),
    'deadline_reminder_days' => env('DEADLINE_REMINDER_DAYS', 3),
    'financial_year_start_month' => 4,
    'roles' => ['ADMIN', 'SENIOR PROGRAM LEAD', 'PROGRAM LEAD', 'CENTRE COORDINATOR', 'VIEWER'],
    'location_types' => ['Education Centre', 'Partner School', 'Mobile Learning Unit', 'Shelter Home', 'Training Centre', 'Community Location', 'Programme Reporting Region'],
    'categories' => ['Input', 'Activity', 'Output', 'Outcome', 'KPI', 'Milestone', 'Qualitative Indicator'],
    'data_types' => ['integer', 'decimal', 'percentage', 'ratio', 'calculated', 'boolean', 'date', 'text', 'long_text', 'single_select', 'multi_select', 'status'],
    'frequencies' => ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'half_yearly' => 'Half-yearly', 'annual' => 'Annual'],
    'target_periods' => ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'half_yearly' => 'Half-yearly', 'annual' => 'Annual'],
    'aggregations' => ['SUM', 'LATEST SNAPSHOT', 'AVERAGE', 'WEIGHTED AVERAGE', 'RATIO', 'UNIQUE COUNT', 'STATUS', 'NON-AGGREGATABLE'],
];
