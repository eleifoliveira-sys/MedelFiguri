<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function assertAvailable(Product $product, int $quantity): void
    {
        if ($quantity < 1) {
            throw ValidationException::withMessages([
                'items' => ['O carrinho contém uma quantidade inválida.'],
            ]);
        }

        if ($product->status !== 'active') {
            throw ValidationException::withMessages([
                'items' => ["O produto {$product->name} não está mais disponível."],
            ]);
        }

        if ($product->stock_quantity < $quantity) {
            throw ValidationException::withMessages([
                'items' => ["Estoque insuficiente para o produto {$product->name}."],
            ]);
        }
    }

    public function decreaseForOrder(
        Product $product,
        int $quantity,
        Order $order,
        User $user,
    ): void {
        $this->assertAvailable($product, $quantity);

        $updated = Product::query()
            ->whereKey($product->id)
            ->where('status', 'active')
            ->where('stock_quantity', '>=', $quantity)
            ->decrement('stock_quantity', $quantity);

        if ($updated !== 1) {
            throw ValidationException::withMessages([
                'items' => ["Estoque insuficiente para o produto {$product->name}."],
            ]);
        }

        InventoryMovement::query()->create([
            'product_id' => $product->id,
            'order_id' => $order->id,
            'movement_type' => 'sale',
            'quantity_delta' => -$quantity,
            'reason' => "Venda no pedido {$order->order_number}",
            'created_by' => $user->id,
        ]);
    }

    public function adjustStock(Product $product, int $delta, string $reason, User $user): Product
    {
        if ($delta === 0) {
            throw ValidationException::withMessages([
                'quantity_delta' => ['O ajuste precisa alterar o estoque.'],
            ]);
        }

        return DB::transaction(function () use ($product, $delta, $reason, $user): Product {
            $lockedProduct = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $newQuantity = $lockedProduct->stock_quantity + $delta;

            if ($newQuantity < 0 || $newQuantity > 2147483647) {
                throw ValidationException::withMessages([
                    'quantity_delta' => ['O ajuste deixaria o estoque fora do limite permitido.'],
                ]);
            }

            $lockedProduct->update(['stock_quantity' => $newQuantity]);
            InventoryMovement::query()->create([
                'product_id' => $lockedProduct->id,
                'movement_type' => 'adjustment',
                'quantity_delta' => $delta,
                'reason' => $reason,
                'created_by' => $user->id,
            ]);

            return $lockedProduct->refresh();
        }, 3);
    }

    public function returnForCancellation(Product $product, int $quantity, Order $order, User $user): void
    {
        if ($quantity < 1) {
            throw ValidationException::withMessages([
                'order' => ['A quantidade de devolução de estoque é inválida.'],
            ]);
        }

        Product::query()->whereKey($product->id)->increment('stock_quantity', $quantity);
        InventoryMovement::query()->create([
            'product_id' => $product->id,
            'order_id' => $order->id,
            'movement_type' => 'return',
            'quantity_delta' => $quantity,
            'reason' => "Devolução de estoque pelo cancelamento do pedido {$order->order_number}",
            'created_by' => $user->id,
        ]);
    }
}
