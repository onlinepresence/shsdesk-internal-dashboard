<?php

/*
|--------------------------------------------------------------------------
| Licence Catalogue (DEPRECATED as a runtime source)
|--------------------------------------------------------------------------
|
| Former mirror of FlowEdu config/licence.php
| (C:\laragon\www\college-school\config\licence.php) plus the hardcoded
| figures from FlowEdu's app/Services/QuoteCalculationService.php.
|
| The live catalogue now lives in the `features` table and the live
| pricing in settings storage. Do not read this config at runtime — it
| is kept solely as seed fallback for CatalogueSeeder and
| SettingsSeeder.
|
| Feature `key`s, labels, descriptions, locked/default flags, `db_column`
| values, prices, multipliers, and bands below are transcribed VERBATIM
| from FlowEdu. Do not rename keys here: heartbeat payloads, stored
| licence JSON, and the catalogue-completeness test all depend on them.
|
| Deliberately NOT mirrored: FlowEdu runtime behaviour keys (`enforce`,
| `student_cap_mode`, `cache_ttl`) — ControlDesk never enforces caps
| locally, it only records and reports licence terms.
|
| Also deliberately NOT mirrored: the STALE `pricing` +
| `student_pricing_bands` system from FlowEdu's config (base_annual
| 12000, 20%-all-modules, 1-100/101-500/501-1000/1000+ bands). Nothing
| live in FlowEdu reads it; the live maths is `core_pricing` +
| `module_pricing` + `bundle_discount` + `founding_client_discount`.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | System Features Catalogue
    |--------------------------------------------------------------------------
    */
    'core_features' => [
        'academic_structure' => [
            'label' => 'Academic Structure',
            'description' => 'Manage faculties, departments, programs, and halls.',
            'locked' => true,
            'default' => true,
        ],
        'students' => [
            'label' => 'Student Management',
            'description' => 'Manage student admissions, profiles, and basic data.',
            'locked' => true,
            'default' => true,
        ],
        'grading' => [
            'label' => 'Grading and Results',
            'description' => 'Record grades, upload results, and generate basic result slips.',
            'locked' => true,
            'default' => true,
        ],
        'teacher_portal' => [
            'label' => 'Teacher Portal',
            'description' => 'Lecturer dashboards, assigned courses, and results entry.',
            'locked' => true,
            'default' => true,
        ],
        'student_portal' => [
            'label' => 'Student Portal',
            'description' => 'Student self-service dashboards, profile views, and courses.',
            'locked' => true,
            'default' => true,
        ],
        'timetable' => [
            'label' => 'Timetable Management',
            'description' => 'Generate and display lecture timetables for programs.',
            'locked' => false,
            'default' => true,
            'db_column' => 'core_timetable',
        ],
        'attendance' => [
            'label' => 'Attendance Tracker',
            'description' => 'Record and track student lecture attendance.',
            'locked' => false,
            'default' => true,
            'db_column' => 'core_attendance',
        ],
        'memos' => [
            'label' => 'Memo Routing & Announcements',
            'description' => 'Internal administrative memos and communications.',
            'locked' => false,
            'default' => true,
            'db_column' => 'core_memos',
        ],
        'impersonation' => [
            'label' => 'Admin Impersonation Tool',
            'description' => 'Allows administrators to log in as other users for troubleshooting.',
            'locked' => false,
            'default' => true,
            'db_column' => 'core_impersonation',
        ],
    ],

    'modules' => [
        'finance' => [
            'label' => 'Financial Portal',
            'description' => 'Fee structures, student payments tracking, outstanding balances, and scholarship grants.',
            'default' => false,
            'base_price' => 2200.00,
            'renewal_base' => 550.00,
            'db_column' => 'module_finance',
        ],
        'staff_hr' => [
            'label' => 'Staff & HR Management',
            'description' => 'Manage non-teaching staff, assignments, teaching/lecturer roles, and personnel directories.',
            'default' => false,
            'base_price' => 1800.00,
            'renewal_base' => 450.00,
            'db_column' => 'module_staff_hr',
        ],
        'reports' => [
            'label' => 'Advanced Reports & Charts',
            'description' => 'Visual graphs and downloadable PDFs for academic progress, financial payments, and attendance.',
            'default' => false,
            'base_price' => 1400.00,
            'renewal_base' => 350.00,
            'db_column' => 'module_reports',
        ],
        'evaluations' => [
            'label' => 'Teacher Evaluations',
            'description' => 'Systematic student evaluation of teachers and course materials review.',
            'default' => false,
            'base_price' => 1600.00,
            'renewal_base' => 400.00,
            'db_column' => 'module_evaluations',
        ],
        'student_welfare' => [
            'label' => 'Student Welfare & Disciplinary',
            'description' => 'Track student medical records, disciplinary actions, and student clearance requests.',
            'default' => false,
            'base_price' => 1500.00,
            'renewal_base' => 380.00,
            'db_column' => 'module_student_welfare',
        ],
        'progression' => [
            'label' => 'Student Promotion & Graduation',
            'description' => 'Manage multi-year academic progression, level-to-level promotions, and final graduation checklists.',
            'default' => false,
            'base_price' => 1200.00,
            'renewal_base' => 300.00,
            'db_column' => 'module_progression',
        ],
        'system_admin' => [
            'label' => 'Advanced Administration',
            'description' => 'Granular roles & permissions settings, user accounts manager, and system backup/restore utilities.',
            'default' => false,
            'base_price' => 1000.00,
            'renewal_base' => 250.00,
            'db_column' => 'module_system_admin',
        ],
        'teacher_tools' => [
            'label' => 'Advanced Teacher Tools',
            'description' => 'Lecturer tools including grade submissions workflow, online announcement postings, and advanced performance charts.',
            'default' => false,
            'base_price' => 900.00,
            'renewal_base' => 220.00,
            'db_column' => 'module_teacher_tools',
        ],
        'messaging' => [
            'label' => 'Secure Messaging Portal',
            'description' => 'Real-time peer-to-peer messaging between lecturers, students, and administration with visual read receipts.',
            'default' => false,
            'base_price' => 1500.00,
            'renewal_base' => 380.00,
            'db_column' => 'module_messaging',
        ],
        'practicum' => [
            'label' => 'Teaching Practice Portal',
            'description' => 'Assign trainees to supervisors, record digital evaluation rubrics, generate performance reports, and display grades to student teachers.',
            'default' => false,
            'base_price' => 2000.00,
            'renewal_base' => 500.00,
            'db_column' => 'module_practicum',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Landing Page Specific Pricing Configuration
    |--------------------------------------------------------------------------
    */
    'core_pricing' => [
        '1-500' => [
            'label' => '1 – 500 students',
            'core_upfront' => 4500.00,
            'core_renewal' => 1200.00,
            'multiplier' => 1.0,
        ],
        '501-1000' => [
            'label' => '501 – 1,000 students',
            'core_upfront' => 6500.00,
            'core_renewal' => 1600.00,
            'multiplier' => 1.3,
        ],
        '1001-2000' => [
            'label' => '1,001 – 2,000 students',
            'core_upfront' => 9000.00,
            'core_renewal' => 2200.00,
            'multiplier' => 1.6,
        ],
        '2001-3500' => [
            'label' => '2,001 – 3,500 students',
            'core_upfront' => 12500.00,
            'core_renewal' => 3000.00,
            'multiplier' => 2.0,
        ],
        '3500+' => [
            'label' => '3,500+ students',
            'custom' => true,
        ],
    ],

    'module_pricing' => [
        'multipliers' => [
            '1-500' => 1.0,
            '501-1000' => 1.3,
            '1001-2000' => 1.6,
            '2001-3500' => 2.0,
        ],
    ],

    'bundle_discount' => 0.12, // 12% discount (matches the 10-15% range)
    'founding_client_discount' => 0.15, // 15% discount (matches the 10-15% range)

    /*
    |--------------------------------------------------------------------------
    | Live quote figures (seed fallback only)
    |--------------------------------------------------------------------------
    |
    | Transcribed from FlowEdu's QuoteCalculationService, where these are
    | hardcoded: hosting setup fees by mode (one-time), implementation
    | addon fees behind the config_setup / migration booleans, per-unit
    | training rates, and the module-count threshold for the bundle
    | discount. Live reads come from settings storage; this array only
    | seeds fresh installs via SettingsSeeder.
    |
    */
    'currency' => 'GHS',

    'bundle_threshold' => 4,

    'fees' => [
        'hosting' => [
            'self_hosted' => 1200.00,
            'managed' => 1600.00,
            'none' => 0.00,
        ],
        'config_setup' => 800.00,
        'migration' => 2000.00,
        'training' => [
            'admin' => 600.00,
            'teacher' => 500.00,
            'onsite' => 1500.00,
        ],
    ],
];
