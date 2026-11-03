<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Models\ProductImage;
use App\Support\CartMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CartItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->product;
        $unitPriceCents = CartMoney::cents((string) $product->price);
        $displayImages = $product->images->filter(
            fn ($productImage): bool => in_array(
                strtolower(pathinfo($productImage->path, PATHINFO_EXTENSION)),
                ['avif', 'gif', 'jpeg', 'jpg', 'png', 'webp'],
                true,
            ),
        );
        $image = $displayImages->firstWhere('is_primary', true) ?? $displayImages->first();
        $model = $product->images->first(
            fn (ProductImage $productImage): bool => preg_match('/\.(glb|gltf)$/i', $productImage->path) === 1
                && Storage::disk('public')->exists($productImage->path),
        );

        return [
            'id' => $this->id,
            'quantity' => $this->quantity,
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'unit_price' => CartMoney::format($unitPriceCents),
                'is_available' => $product->status === 'active' && $product->stock_quantity > 0,
                'available_quantity' => $product->status === 'active' ? $product->stock_quantity : 0,
                'model_path' => $model?->path,
                'image' => $image === null ? null : [
                    'path' => $image->path,
                    'alt_text' => $image->alt_text,
                ],
            ],
            'subtotal' => CartMoney::format(CartMoney::multiplyCents($unitPriceCents, $this->quantity)),
        ];
    }
}
