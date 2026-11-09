<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\Users\User;
use App\Support\CartMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    private const MAX_DECIMAL_CENTS = '9999999999';

    public function __construct(private readonly InventoryService $inventoryService) {}

    /** @param array{recipient_name: string, cep: string, street: string, number: string, complement?: ?string, neighborhood: string, city: string, state: string} $deliveryAddress */
    public function checkout(User $user, array $deliveryAddress): Order
    {
        return DB::transaction(function () use ($user, $deliveryAddress): Order {
            $cart = Cart::query()
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->whereNull('merged_into_id')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($cart === null) {
                throw ValidationException::withMessages([
                    'cart' => ['O carrinho está vazio.'],
                ]);
            }

            $items = $cart->items()->orderBy('id')->lockForUpdate()->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'cart' => ['O carrinho está vazio.'],
                ]);
            }

            $products = Product::query()
                ->whereIn('id', $items->pluck('product_id')->unique())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $subtotalCents = '0';
            $pricedItems = [];

            foreach ($items as $item) {
                $product = $products->get($item->product_id);

                if ($product === null) {
                    throw ValidationException::withMessages([
                        'items' => ['Um produto do carrinho não está mais disponível.'],
                    ]);
                }

                $this->inventoryService->assertAvailable($product, (int) $item->quantity);
                $unitPriceCents = CartMoney::cents((string) $product->price);
                $lineTotalCents = CartMoney::multiplyCents($unitPriceCents, (int) $item->quantity);
                $this->assertFitsOrderDecimal($lineTotalCents);
                $subtotalCents = CartMoney::addCents($subtotalCents, $lineTotalCents);
                $pricedItems[] = [
                    'item' => $item,
                    'product' => $product,
                    'unit_price' => CartMoney::format($unitPriceCents),
                    'line_total' => CartMoney::format($lineTotalCents),
                ];
            }

            $this->assertFitsOrderDecimal($subtotalCents);
            $shippingCost = '0.00';
            $totalCents = CartMoney::addCents($subtotalCents, '0');
            $this->assertFitsOrderDecimal($totalCents);

            $order = Order::query()->create([
                'order_number' => 'MF-'.now()->format('Ymd').'-'.Str::upper(Str::random(12)),
                'user_id' => $user->id,
                'status' => 'pending',
                'subtotal' => CartMoney::format($subtotalCents),
                'shipping_cost' => $shippingCost,
                'discount_amount' => '0.00',
                'total' => CartMoney::format($totalCents),
                'payment_method' => 'pending',
            ]);

            foreach ($pricedItems as $pricedItem) {
                $item = $pricedItem['item'];
                /** @var Product $product */
                $product = $pricedItem['product'];

                $order->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'unit_price' => $pricedItem['unit_price'],
                    'quantity' => $item->quantity,
                    'line_total' => $pricedItem['line_total'],
                ]);

                $this->inventoryService->decreaseForOrder(
                    $product,
                    (int) $item->quantity,
                    $order,
                    $user,
                );
            }

            $order->address()->create([
                ...$deliveryAddress,
                'state' => strtoupper($deliveryAddress['state']),
            ]);
            $cart->update(['status' => 'converted']);

            return $order->load(['items', 'address']);
        }, 3);
    }

    private function assertFitsOrderDecimal(string $cents): void
    {
        if (strlen($cents) > strlen(self::MAX_DECIMAL_CENTS)) {
            throw ValidationException::withMessages([
                'cart' => ['O valor do pedido excede o limite permitido.'],
            ]);
        }
    }
}
