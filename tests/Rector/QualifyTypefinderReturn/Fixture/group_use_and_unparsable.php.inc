<?php

namespace App\Http\Resources;

use App\Models\{Post, User as Author};
use Illuminate\Http\Resources\Json\JsonResource;

final class PostResource extends JsonResource
{
    /** @typefinder-return array{post: Post, author: Author} */
    public function toArray($request): array
    {
        return [];
    }

    /** @typefinder-return array{ */
    public function broken(): array
    {
        return [];
    }

    /** @typefinder-return array{count: int} */
    public function counts(): array
    {
        return [];
    }

    /** A plain doc. */
    public function plain(): array
    {
        return [];
    }
}
?>
-----
<?php

namespace App\Http\Resources;

use App\Models\{Post, User as Author};
use Illuminate\Http\Resources\Json\JsonResource;

final class PostResource extends JsonResource
{
    /** @typefinder-return array{post: \App\Models\Post, author: \App\Models\User} */
    public function toArray($request): array
    {
        return [];
    }

    /** @typefinder-return array{ */
    public function broken(): array
    {
        return [];
    }

    /** @typefinder-return array{count: int} */
    public function counts(): array
    {
        return [];
    }

    /** A plain doc. */
    public function plain(): array
    {
        return [];
    }
}
?>
