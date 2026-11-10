<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;

/** Appended accessors in every form the extractor reads (issue #94). */
#[TypefinderOverrides(['palette' => '{ primary: string }'])]
final class Badge extends Model
{
    protected $table = 'tags';

    protected $guarded = [];

    protected $hidden = ['secret'];

    protected $appends = ['shout', 'length', 'subtitle', 'initial', 'mystery', 'palette', 'secret', 'name'];

    public function getShoutAttribute(): string
    {
        return strtoupper((string) $this->name);
    }

    public function getLengthAttribute(): int
    {
        return strlen((string) $this->name);
    }

    public function getSubtitleAttribute(): ?string
    {
        return null;
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function initial(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->name === null ? null : substr((string) $this->name, 0, 1));
    }

    protected function mystery(): Attribute
    {
        return Attribute::get(fn (): array => []);
    }

    public function getPaletteAttribute(): array
    {
        return ['primary' => '#000'];
    }

    public function getSecretAttribute(): string
    {
        return 'hidden';
    }
}
