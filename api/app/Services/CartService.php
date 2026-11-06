<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CartService
{
    public function current(?User $user, ?string $guestToken): Cart
    {
        if ($user !== null) {
            return $this->currentForUser($user);
        }

        $token = $this->isValidGuestToken($guestToken) ? $guestToken : Str::random(64);

        $cart = Cart::query()
            ->where('guest_token', $token)
            ->where('status', 'active')
            ->whereNull('merged_into_id')
            ->first();

        if ($cart !== null) {
            return $cart->load('items.product.images');
        }

        if (Cart::query()->where('guest_token', $token)->exists()) {
            $token = Str::random(64);
        }

        try {
            $cart = Cart::query()->create([
                'guest_token' => $token,
                'status' => 'active',
            ]);
        } catch (QueryException $exception) {
            $cart = Cart::query()
                ->where('guest_token', $token)
                ->where('status', 'active')
                ->whereNull('merged_into_id')
                ->first();

            if ($cart === null) {
                throw $exception;
            }
        }

        return $cart->load('items.product.images');
    }

    public function guestByToken(?string $guestToken): ?Cart
    {
        if (! $this->isValidGuestToken($guestToken)) {
            return null;
        }

        return Cart::query()
            ->where('guest_token', $guestToken)
            ->whereNull('user_id')
            ->where('status', 'active')
            ->whereNull('merged_into_id')
            ->first()
            ?->load('items.product.images');
    }

    public function addItem(Cart $cart, int $productId, int $quantity): Cart
    {
        DB::transaction(function () use ($cart, $productId, $quantity): void {
            $lockedCart = Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $product = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
            $this->ensureAvailable($product, $quantity);

            $item = $lockedCart->items()
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();

            if ($item === null) {
                $lockedCart->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                ]);
            } else {
                $item->update(['quantity' => $quantity]);
            }
        });

        return $cart->fresh()->load('items.product.images');
    }

    public function updateItem(Cart $cart, int $itemId, int $quantity): Cart
    {
        DB::transaction(function () use ($cart, $itemId, $quantity): void {
            $lockedCart = Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $item = $lockedCart->items()->whereKey($itemId)->lockForUpdate()->firstOrFail();
            $product = Product::query()->whereKey($item->product_id)->lockForUpdate()->firstOrFail();
            $this->ensureAvailable($product, $quantity);

            $item->update(['quantity' => $quantity]);
        });

        return $cart->fresh()->load('items.product.images');
    }

    public function removeItem(Cart $cart, int $itemId): Cart
    {
        DB::transaction(function () use ($cart, $itemId): void {
            $lockedCart = Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $lockedCart->items()->whereKey($itemId)->firstOrFail()->delete();
        });

        return $cart->fresh()->load('items.product.images');
    }

    /**
     * @return array{status: string, conflicts?: list<array{product_id: int, name: string, requested_quantity: int, available_quantity: int, reason: string}>, already_merged?: bool, merged_quantity?: int}
     */
    public function mergeGuestCart(User $user, ?string $guestToken): array
    {
        if (! $this->isValidGuestToken($guestToken)) {
            return ['status' => 'no_guest_cart'];
        }

        return DB::transaction(function () use ($user, $guestToken): array {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $guestCart = Cart::query()
                ->where('guest_token', $guestToken)
                ->lockForUpdate()
                ->first();

            if ($guestCart === null) {
                return ['status' => 'no_guest_cart'];
            }

            if ($guestCart->status === 'merged' && $guestCart->merged_into_id !== null) {
                $destination = Cart::query()
                    ->whereKey($guestCart->merged_into_id)
                    ->where('user_id', $lockedUser->id)
                    ->where('status', 'active')
                    ->whereNull('merged_into_id')
                    ->first();

                return $destination === null
                    ? ['status' => 'no_guest_cart']
                    : ['status' => 'merged', 'already_merged' => true, 'merged_quantity' => 0];
            }

            if ($guestCart->status !== 'active' || $guestCart->user_id !== null || $guestCart->merged_into_id !== null) {
                return ['status' => 'no_guest_cart'];
            }

            $guestItems = $guestCart->items()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $mergedQuantity = (int) $guestItems->sum('quantity');

            $targetCart = Cart::query()
                ->where('user_id', $lockedUser->id)
                ->where('status', 'active')
                ->whereNull('merged_into_id')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $targetItems = $targetCart === null
                ? collect()
                : $targetCart->items()->orderBy('id')->lockForUpdate()->get();

            $guestQuantities = $guestItems->keyBy('product_id');
            $targetQuantities = $targetItems->keyBy('product_id');
            $productIds = $guestItems
                ->pluck('product_id')
                ->merge($targetItems->pluck('product_id'))
                ->unique()
                ->sort()
                ->values();
            $products = Product::query()
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $plannedQuantities = [];
            $conflicts = [];

            foreach ($productIds as $productId) {
                $guestItem = $guestQuantities->get($productId);
                $targetItem = $targetQuantities->get($productId);
                $requestedQuantity = (int) ($guestItem?->quantity ?? 0)
                    + (int) ($targetItem?->quantity ?? 0);
                $product = $products->get($productId);

                if ($product === null) {
                    $conflicts[] = [
                        'product_id' => (int) $productId,
                        'name' => 'Produto indisponível',
                        'requested_quantity' => $requestedQuantity,
                        'available_quantity' => 0,
                        'reason' => 'product_unavailable',
                    ];

                    continue;
                }

                if ($product->status !== 'active') {
                    $conflicts[] = [
                        'product_id' => $product->id,
                        'name' => $product->name,
                        'requested_quantity' => $requestedQuantity,
                        'available_quantity' => 0,
                        'reason' => 'product_unavailable',
                    ];

                    continue;
                }

                if ($product->stock_quantity < 1 || $requestedQuantity > $product->stock_quantity) {
                    $conflicts[] = [
                        'product_id' => $product->id,
                        'name' => $product->name,
                        'requested_quantity' => $requestedQuantity,
                        'available_quantity' => $product->stock_quantity,
                        'reason' => 'insufficient_stock',
                    ];

                    continue;
                }

                $plannedQuantities[$product->id] = [
                    'quantity' => $requestedQuantity,
                    'guest_item' => $guestItem,
                    'target_item' => $targetItem,
                ];
            }

            if ($conflicts !== []) {
                return [
                    'status' => 'needs_attention',
                    'conflicts' => $conflicts,
                ];
            }

            if ($targetCart === null) {
                $targetCart = $lockedUser->carts()->create(['status' => 'active']);
            }

            foreach ($plannedQuantities as $planned) {
                /** @var CartItem|null $guestItem */
                $guestItem = $planned['guest_item'];
                /** @var CartItem|null $targetItem */
                $targetItem = $planned['target_item'];

                if ($guestItem === null) {
                    continue;
                }

                if ($targetItem === null) {
                    $targetCart->items()->create([
                        'product_id' => $guestItem->product_id,
                        'quantity' => $planned['quantity'],
                    ]);
                } else {
                    $targetItem->update(['quantity' => $planned['quantity']]);
                }
            }

            $guestCart->items()->delete();
            $guestCart->update([
                'status' => 'merged',
                'merged_into_id' => $targetCart->id,
            ]);

            return [
                'status' => 'merged',
                'merged_quantity' => $mergedQuantity,
            ];
        }, 3);
    }

    private function currentForUser(User $user): Cart
    {
        return DB::transaction(function () use ($user): Cart {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $cart = Cart::query()
                ->where('user_id', $lockedUser->id)
                ->where('status', 'active')
                ->whereNull('merged_into_id')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($cart === null) {
                $cart = $lockedUser->carts()->create(['status' => 'active']);
            }

            return $cart->load('items.product.images');
        });
    }

    private function ensureAvailable(Product $product, int $quantity): void
    {
        if ($product->status !== 'active') {
            throw ValidationException::withMessages([
                'product_id' => ['Este produto não está disponível.'],
            ]);
        }

        if ($product->stock_quantity < 1 || $quantity > $product->stock_quantity) {
            throw ValidationException::withMessages([
                'quantity' => ['A quantidade solicitada não está disponível em estoque.'],
            ]);
        }
    }

    private function isValidGuestToken(?string $token): bool
    {
        return $token !== null
            && strlen($token) >= 32
            && strlen($token) <= 128
            && preg_match('/\A[A-Za-z0-9]+\z/', $token) === 1;
    }
}
