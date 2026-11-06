<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'paid_at' => $this->paid_at?->toISOString(),
            'subtotal' => $this->subtotal,
            'shipping_cost' => $this->shipping_cost,
            'discount_amount' => $this->discount_amount,
            'total' => $this->total,
            'created_at' => $this->created_at?->toISOString(),
            'address' => $this->address === null ? null : [
                'recipient_name' => $this->address->recipient_name,
                'cep' => $this->address->cep,
                'street' => $this->address->street,
                'number' => $this->address->number,
                'complement' => $this->address->complement,
                'neighborhood' => $this->address->neighborhood,
                'city' => $this->address->city,
                'state' => $this->address->state,
            ],
            'items' => $this->items->map(fn ($item): array => [
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'product_sku' => $item->product_sku,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'line_total' => $item->line_total,
            ])->values(),
        ];
    }
}
