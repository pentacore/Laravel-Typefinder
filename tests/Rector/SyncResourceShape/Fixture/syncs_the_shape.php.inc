<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MemberResource extends JsonResource
{
    /**
     * The member.
     *
     * @return array{
     *     id: int,
     *     legacy: string,
     *     status: string,
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->whenLoaded('status'),
            'name' => 'fixed',
            'email' => $this->when($request->boolean('admin'), 'hidden'),
        ];
    }
}
?>
-----
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MemberResource extends JsonResource
{
    /**
     * The member.
     *
     * @return array{
     *     id: int,
     *     status?: string,
     *     name: string,
     *     email?: mixed,
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->whenLoaded('status'),
            'name' => 'fixed',
            'email' => $this->when($request->boolean('admin'), 'hidden'),
        ];
    }
}
?>
