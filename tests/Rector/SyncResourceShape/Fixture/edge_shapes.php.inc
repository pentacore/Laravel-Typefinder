<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Attributes\TypefinderResource;

#[TypefinderResource(shape: ['id' => 'number'])]
final class AttributeResource extends JsonResource
{
    /** @return array{gone: int} */
    public function toArray($request): array
    {
        return ['id' => 1];
    }
}

final class UndocumentedResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => 1];
    }
}

final class LooseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return ['id' => 1];
    }
}

final class ReplacedResource extends JsonResource
{
    /** @return array{'old key': int, 0: string} */
    public function toArray($request): array
    {
        return ['new key' => 'x'];
    }
}

final class EmptyResource extends JsonResource
{
    /** @return array{} */
    public function toArray($request): array
    {
        if ($request->boolean('short')) {
            return ['value' => 'x'];
        }

        return ['value' => 1];
    }
}
?>
-----
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Attributes\TypefinderResource;

#[TypefinderResource(shape: ['id' => 'number'])]
final class AttributeResource extends JsonResource
{
    /** @return array{gone: int} */
    public function toArray($request): array
    {
        return ['id' => 1];
    }
}

final class UndocumentedResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => 1];
    }
}

final class LooseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return ['id' => 1];
    }
}

final class ReplacedResource extends JsonResource
{
    /** @return array{'new key': string} */
    public function toArray($request): array
    {
        return ['new key' => 'x'];
    }
}

final class EmptyResource extends JsonResource
{
    /** @return array{value: mixed} */
    public function toArray($request): array
    {
        if ($request->boolean('short')) {
            return ['value' => 'x'];
        }

        return ['value' => 1];
    }
}
?>
