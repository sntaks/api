<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GameResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'launch_url' => $this->launch_url,
            'status' => $this->status,
            'sort_order' => $this->sort_order,
            'products' => ProductResource::collection($this->whenLoaded('products')),
        ];
    }
}
