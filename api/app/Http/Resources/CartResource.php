<?php

namespace App\Http\Resources;

use App\Support\CartMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $items = $this->items;
        $subtotalCents = 0;

        foreach ($items as $item) {
            $unitPriceCents = CartMoney::cents((string) $item->product->price);
            $subtotalCents = CartMoney::addCents(
                $subtotalCents,
                CartMoney::multiplyCents($unitPriceCents, $item->quantity),
            );
        }

        return [
            'id' => $this->id,
            'status' => $this->status,
            'items' => CartItemResource::collection($items),
            'total_quantity' => $items->sum('quantity'),
            'subtotal' => CartMoney::format($subtotalCents),
        ];
    }
}
