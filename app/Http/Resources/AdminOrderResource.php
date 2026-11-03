<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...OrderResource::make($this->resource)->toArray($request),
            'customer' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ],
            'inventory_movements' => $this->whenLoaded(
                'inventoryMovements',
                fn () => AdminInventoryMovementResource::collection($this->inventoryMovements),
            ),
        ];
    }
}
