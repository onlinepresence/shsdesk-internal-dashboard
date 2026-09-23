<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sidebar Navigation
    |--------------------------------------------------------------------------
    |
    | Groups render in order inside the app sidebar. Each group has a section
    | label and items carrying a label, route name, and icon key. Icons are
    | inline SVGs mapped in the sidebar link component — add a path there
    | when introducing a new icon key. Items accept an optional 'params'
    | array for routes that need parameters.
    |
    | Future groups (Deployments, Licences, Users, Audit, Settings) slot in
    | as new entries here without any markup changes. Items accept an
    | optional 'can' ability — gated items hide for users lacking it.
    |
    */

    'groups' => [
        [
            'section' => 'Main',
            'items' => [
                ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'dashboard'],
                ['label' => 'Profile', 'route' => 'profile', 'icon' => 'user'],
            ],
        ],
        [
            'section' => 'Manage',
            'items' => [
                ['label' => 'Deployments', 'route' => 'deployments.index', 'icon' => 'server', 'can' => 'ops.access'],
                ['label' => 'Licences', 'route' => 'licences.index', 'icon' => 'key', 'can' => 'ops.access'],
                ['label' => 'Invoices', 'route' => 'invoices.index', 'icon' => 'receipt', 'can' => 'ops.access'],
                ['label' => 'Catalogue', 'route' => 'catalogue.index', 'icon' => 'sliders', 'can' => 'manage-catalogue'],
                ['label' => 'Users', 'route' => 'users.index', 'icon' => 'users', 'can' => 'manage-users'],
            ],
        ],
        [
            'section' => 'Products',
            'items' => [
                ['label' => 'Products', 'route' => 'products.index', 'icon' => 'package', 'can' => 'ops.access'],
                ['label' => 'Leads', 'route' => 'leads.index', 'icon' => 'inbox', 'can' => 'ops.access'],
                ['label' => 'Demo keys', 'route' => 'demo-keys.index', 'icon' => 'ticket', 'can' => 'ops.access'],
            ],
        ],
    ],

];
