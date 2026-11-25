<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use App\Enums\PostStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Tests\Fixtures\Ignored\Enums\UnbackedStatus;

/** Accessors typed only by their PHP return types (#148). */
final class AccessorTypes extends Model
{
    protected $table = 'tags';

    protected $appends = ['full_name', 'shout', 'status', 'mode', 'owner', 'either', 'maybe_owner', 'phantom', 'exploding', 'loose', 'odd'];

    protected function fullName(): Attribute
    {
        return Attribute::make(get: fn (): string => (string) $this->name);
    }

    public function getShoutAttribute(): array
    {
        return [];
    }

    public function getStatusAttribute(): PostStatus
    {
        return PostStatus::Draft;
    }

    public function getModeAttribute(): UnbackedStatus
    {
        return UnbackedStatus::On;
    }

    public function getOwnerAttribute(): User
    {
        return new User;
    }

    public function getEitherAttribute(): int|string
    {
        return $this->name === null ? 1 : (string) $this->name;
    }

    public function getLooseAttribute(): mixed
    {
        return $this->name;
    }

    public function getOddAttribute(): int|\stdClass
    {
        return $this->name === null ? 1 : new \stdClass;
    }

    protected function exploding(): Attribute
    {
        throw new \LogicException('not buildable');
    }

    protected function maybeOwner(): Attribute
    {
        return Attribute::get(fn (): ?User => null);
    }
}
