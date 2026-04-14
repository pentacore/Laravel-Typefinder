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

    'output_path' => resource_path('js/typefinder'),

    /*
    |--------------------------------------------------------------------------
    | Auto .gitignore
    |--------------------------------------------------------------------------
    |
    | When enabled, the generator appends the output path to the project
    | root .gitignore (if present) on every run so generated .d.ts files
    | stay out of version control. Idempotent — adds the line at most once.
    |
    */

    'gitignore_generated' => true,

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

        // When enabled, each model emits {Model}Create and {Model}Update
        // companion types alongside the canonical read shape.
        'emit_write_shapes' => true,

        // Respect $fillable/$guarded when building write shapes.
        // Override per-model via typefinderRespectMassAssignment().
        'respect_mass_assignment' => true,

        // Columns excluded from the Update shape. Merged with each model's
        // typefinderImmutableOnUpdate() list.
        'immutable_on_update' => ['id', 'created_at', 'updated_at', 'deleted_at'],
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

    /*
    |--------------------------------------------------------------------------
    | Inertia (opt-in)
    |--------------------------------------------------------------------------
    |
    | When enabled, the generator scans controller methods for
    | #[Pentacore\Typefinder\Attributes\TypefinderPage] attributes and emits a
    | consolidated pages.d.ts describing the PageProps map.
    |
    */

    'inertia' => [
        'enabled' => false,
        'paths' => [app_path('Http/Controllers')],
    ],

    /*
    |--------------------------------------------------------------------------
    | Broadcasting (opt-in)
    |--------------------------------------------------------------------------
    |
    | When enabled, classes implementing ShouldBroadcast in the configured
    | paths are scanned and emitted into broadcasting.d.ts as a set of
    | channel → event → payload maps.
    |
    */

    'broadcasting' => [
        'enabled' => false,
        'paths' => [app_path('Events')],
    ],
];
