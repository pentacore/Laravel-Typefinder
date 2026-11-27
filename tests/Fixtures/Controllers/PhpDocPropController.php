<?php

declare(strict_types=1);

namespace Tests\Fixtures\Controllers;

use App\Enums\PostStatus;
use App\Http\Resources\UserResource;
use App\Models\User;
use Pentacore\Typefinder\Attributes\TypefinderPage;

/**
 * Page props written as PHPDoc types (#247). Short class names resolve
 * through this file's imports.
 *
 * @see PostStatus
 * @see UserResource
 */
class PhpDocPropController
{
    #[TypefinderPage('PhpDoc/Show', [
        'shape' => 'array{id: int, name?: string, tags: list<string>}',
        'counts' => 'array<string, int>',
        'ids' => 'int[]',
        'maybe' => '?string',
        'users' => 'list<User>',
        'statuses' => 'list<PostStatus>',
        'editor' => '?UserResource',
        'anything' => 'mixed',
        'ts' => '{ id: number; name: string }',
        'tsGeneric' => 'Array<number>',
        'tsRecord' => 'Record<string, number>',
        'literal' => "'a' | 'b'",
        'model' => User::class,
        'nullable' => 'string | null',
        'classStringList' => 'list<'.User::class.'>',
        'classStringNullable' => '?'.UserResource::class,
    ])]
    public function show(): void {}

    #[TypefinderPage('PhpDoc/Broken', [
        'unbalanced' => 'array{id: int',
        'empty' => '',
        'callable' => 'callable',
        'mixed' => 'array<string, number>',
    ])]
    public function broken(): void {}
}
