<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\Users\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(private readonly InventoryService $inventoryService) {}

    public function listFor(User $user): LengthAwarePaginator
    {
        return Order::query()
            ->where('user_id', $user->id)
            ->with(['items', 'address'])
            ->latest('id')
            ->paginate(15);
    }

    public function findFor(User $user, int $orderId): Order
    {
        return Order::query()
            ->where('user_id', $user->id)
            ->with(['items', 'address'])
            ->findOrFail($orderId);
    }

    /** @return array{order: Order, already_cancelled: bool, inventory_restored: bool} */
    public function cancel(User $user, int $orderId): array
    {
        return DB::transaction(function () use ($user, $orderId): array {
            $order = Order::query()
                ->whereKey($orderId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status === 'cancelled') {
                return [
                    'order' => $order->load(['items', 'address']),
                    'already_cancelled' => true,
                    'inventory_restored' => $order->inventoryMovements()
                        ->where('movement_type', 'return')
                        ->exists(),
                ];
            }

            if (! in_array($order->status, ['pending', 'paid'], true)) {
                throw ValidationException::withMessages([
                    'order' => ['Pedidos em preparação, enviados ou entregues não podem ser cancelados por este endpoint.'],
                ]);
            }

            $saleMovements = InventoryMovement::query()
                ->where('order_id', $order->id)
                ->where('movement_type', 'sale')
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get()
                ->groupBy('product_id');

            $productIds = $saleMovements->keys()->sort()->values();
            $products = Product::query()
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $restored = false;
            foreach ($saleMovements as $productId => $movements) {
                $quantity = (int) $movements->sum('quantity_delta') * -1;
                if ($quantity < 1) {
                    continue;
                }

                $product = $products->get($productId);
                if ($product === null) {
                    throw ValidationException::withMessages([
                        'order' => ['Não foi possível restaurar o estoque deste pedido.'],
                    ]);
                }

                $this->inventoryService->returnForCancellation($product, $quantity, $order, $user);
                $restored = true;
            }

            $order->update(['status' => 'cancelled']);

            return [
                'order' => $order->load(['items', 'address']),
                'already_cancelled' => false,
                'inventory_restored' => $restored,
            ];
        }, 3);
    }

    /** @param list<string> $allowedStatuses */
    public function transitionForAdmin(int $orderId, string $nextStatus, array $allowedStatuses): Order
    {
        return DB::transaction(function () use ($orderId, $nextStatus, $allowedStatuses): Order {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();

            $transitions = [
                'paid' => ['processing'],
                'processing' => ['shipped'],
                'shipped' => ['delivered'],
            ];

            if (! in_array($nextStatus, $allowedStatuses, true)
                || ! in_array($nextStatus, $transitions[$order->status] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Esta transição de pedido não é permitida.'],
                ]);
            }

            $order->update(['status' => $nextStatus]);

            return $order->load(['items', 'address']);
        }, 3);
    }
}
