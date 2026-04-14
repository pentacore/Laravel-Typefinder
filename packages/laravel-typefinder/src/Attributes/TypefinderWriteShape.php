<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Attributes;

use Attribute;

/**
 * Tune the generated `{Model}Create` and `{Model}Update` companion types.
 *
 * Typefinder infers these shapes from the model's columns plus conventions
 * (primary key + timestamp columns are server-filled and omitted from Create;
 * everything becomes optional on Update; `$fillable` / `$guarded` are honoured
 * by default). This attribute lets a single model diverge from the global
 * policy when the conventions don't fit.
 *
 * Example:
 * ```php
 * use Pentacore\Typefinder\Attributes\TypefinderWriteShape;
 *
 * #[TypefinderWriteShape(
 *     serverFilled: ['uuid', 'external_ref'],   // extra fields omitted from Create
 *     respectMassAssignment: false,             // ignore $fillable/$guarded for this model
 *     immutableOnUpdate: ['customer_id'],       // extra fields excluded from Update
 * )]
 * class Invoice extends \Illuminate\Database\Eloquent\Model {}
 * ```
 *
 * Only applies when `config('typefinder.models.emit_write_shapes')` is enabled.
 *
 * @see TypefinderOverrides for replacing field types entirely.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TypefinderWriteShape
{
    /**
     * @param  list<string>  $serverFilled  Extra columns omitted from the Create shape
     *                                      (on top of the primary key and timestamps).
     * @param  ?bool  $respectMassAssignment  Override the global
     *                                        `typefinder.models.respect_mass_assignment`
     *                                        for this model. Pass `null` to inherit.
     * @param  list<string>  $immutableOnUpdate  Extra columns excluded from the Update shape
     *                                           (on top of the config-level immutable list).
     */
    public function __construct(
        public array $serverFilled = [],
        public ?bool $respectMassAssignment = null,
        public array $immutableOnUpdate = [],
    ) {}
}
