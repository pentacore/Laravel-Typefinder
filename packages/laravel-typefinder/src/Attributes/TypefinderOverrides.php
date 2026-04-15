<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Attributes;

use Attribute;

/**
 * Override or add fields on a generated type. Applies to models (affects the
 * read shape and every write-shape companion) and form requests (replaces the
 * inferred type for the matching top-level field).
 *
 * Values may be literal TypeScript strings, model class-strings (resolved to
 * the matching generated model type), enum class-strings (same, resolved via
 * the enum barrel), or JsonResource class-strings. Anything unrecognised is
 * emitted verbatim.
 *
 * Highest priority in the model type-resolution chain — wins over casts,
 * column types, and relationship inference.
 *
 * Example on a model:
 * <pre>
 * use Pentacore\Typefinder\Attributes\TypefinderOverrides;
 *
 * #[TypefinderOverrides([
 *     'metadata' => 'Record<string, string>',       // replace inferred type
 *     'full_title' => 'string',                     // virtual accessor
 *     'owner' => \App\Models\User::class,           // resolved to the generated User type
 * ])]
 * class Post extends \Illuminate\Database\Eloquent\Model {}
 * </pre>
 *
 * Example on a FormRequest:
 * <pre>
 * #[TypefinderOverrides(['attachment' => 'File | null', 'amount' => 'number'])]
 * class StoreInvoiceRequest extends FormRequest
 * {
 *     public function rules(): array { … }
 * }
 * </pre>
 *
 * @see TypefinderWriteShape for tuning Create/Update companions.
 * @see TypefinderIgnore to skip a class entirely.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TypefinderOverrides
{
    /**
     * @param  array<string, string>  $overrides  Field name → TS type string or class-string.
     */
    public function __construct(public array $overrides) {}
}
