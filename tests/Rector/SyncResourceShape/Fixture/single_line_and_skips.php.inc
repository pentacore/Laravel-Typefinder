<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

final class TagResource extends JsonResource
{
    /** @return array{id: int, gone: string, extra: bool} */
    public function toArray($request): array
    {
        return ['id' => 1, 'label' => 'x'];
    }
}

final class OpenResource extends JsonResource
{
    /** @return array{id: int, gone: string} */
    public function toArray($request): array
    {
        return ['id' => 1, ...parent::toArray($request)];
    }
}

final class NotAResource
{
    /** @return array{id: int} */
    public function toArray(): array
    {
        return ['name' => 'x'];
    }
}
?>
-----
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

final class TagResource extends JsonResource
{
    /** @return array{id: int, label: string} */
    public function toArray($request): array
    {
        return ['id' => 1, 'label' => 'x'];
    }
}

final class OpenResource extends JsonResource
{
    /** @return array{id: int, gone: string} */
    public function toArray($request): array
    {
        return ['id' => 1, ...parent::toArray($request)];
    }
}

final class NotAResource
{
    /** @return array{id: int} */
    public function toArray(): array
    {
        return ['name' => 'x'];
    }
}
?>
