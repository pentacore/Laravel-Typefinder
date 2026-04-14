<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Output Path
    |--------------------------------------------------------------------------
    |
    | The directory where generated .d.ts files will be written.
    |
    */

    'output_path' => resource_path('js/types'),

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Configuration for model type generation.
    |
    */

    'models' => [
        'enabled' => true,
        'paths' => [app_path('Models')],
        'include_relationships' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Enums
    |--------------------------------------------------------------------------
    |
    | Configuration for enum type generation.
    |
    */

    'enums' => [
        'enabled' => true,
        'paths' => [app_path('Enums')],
    ],

    /*
    |--------------------------------------------------------------------------
    | Requests
    |--------------------------------------------------------------------------
    |
    | Configuration for form request type generation.
    |
    */

    'requests' => [
        'enabled' => true,
        'paths' => [app_path('Http/Requests')],
        'extract_nested' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Casts
    |--------------------------------------------------------------------------
    |
    | Override or extend the built-in cast-to-TypeScript type mappings.
    |
    */

    'casts' => [
        'type_map' => [
            // 'datetime' => 'Date',
        ],
    ],
];
