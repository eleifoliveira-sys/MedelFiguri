<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CheckoutApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sanctum.stateful', ['localhost:8080', '127.0.0.1:8080']);
        $this->withHeader('Origin', 'http://localhost:8080');
        $this->withCredentials();
    }

    public function test_checkout_requires_authentication(): void
    {
        $this->postJson('/api/checkout', ['address' => $this->address()])
            ->assertUnauthorized();

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_rejects_an_empty_cart(): void
    {
        $user = User::factory()->create();
        $user->carts()->create(['status' => 'active']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout', ['address' => $this->address()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cart');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_repeating_checkout_cannot_create_a_second_order_from_a_converted_cart(): void
    {
        $user = User::factory()->create();
        $cart = $user->carts()->create(['status' => 'active']);
        $product = $this->createProduct();
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout', ['address' => $this->address()])
            ->assertCreated();

        $this->postJson('/api/checkout', ['address' => $this->address()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cart');

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(4, $product->fresh()->stock_quantity);
    }

    public function test_checkout_creates_order_snapshots_and_decreases_inventory(): void
    {
        $user = User::factory()->create();
        $cart = $user->carts()->create(['status' => 'active']);
        $firstProduct = $this->createProduct([
            'sku' => 'CHECKOUT-FIRST',
            'name' => 'Figure histórica',
            'price' => '49.95',
            'stock_quantity' => 5,
        ]);
        $secondProduct = $this->createProduct([
            'sku' => 'CHECKOUT-SECOND',
            'price' => '10.00',
            'stock_quantity' => 3,
        ]);
        $cart->items()->create(['product_id' => $firstProduct->id, 'quantity' => 2]);
        $cart->items()->create(['product_id' => $secondProduct->id, 'quantity' => 1]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout', [
                'address' => $this->address(['state' => 'sp']),
                'user_id' => 99999,
                'cart_id' => 99999,
                'price' => '0.01',
                'subtotal' => '0.01',
                'shipping_cost' => '999.99',
                'total' => '0.01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.subtotal', '109.90')
            ->assertJsonPath('data.shipping_cost', '0.00')
            ->assertJsonPath('data.discount_amount', '0.00')
            ->assertJsonPath('data.total', '109.90')
            ->assertJsonPath('data.address.recipient_name', 'Cliente de teste')
            ->assertJsonPath('data.address.state', 'SP')
            ->assertJsonPath('data.items.0.product_name', 'Figure histórica')
            ->assertJsonPath('data.items.0.product_sku', 'CHECKOUT-FIRST')
            ->assertJsonPath('data.items.0.unit_price', '49.95')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.line_total', '99.90')
            ->assertJsonMissingPath('data.user_id')
            ->assertJsonPath('data.payment_method', 'pending')
            ->assertJsonPath('data.paid_at', null);

        $orderId = $response->json('data.id');
        $this->assertSame(2, $response->json('data.items.0.quantity'));
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'user_id' => $user->id,
            'subtotal' => '109.90',
            'shipping_cost' => '0.00',
            'total' => '109.90',
            'payment_method' => 'pending',
        ]);
        $this->assertDatabaseHas('order_addresses', [
            'order_id' => $orderId,
            'recipient_name' => 'Cliente de teste',
            'cep' => '01001-000',
            'state' => 'SP',
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'product_id' => $firstProduct->id,
            'product_name' => 'Figure histórica',
            'product_sku' => 'CHECKOUT-FIRST',
            'unit_price' => '49.95',
            'quantity' => 2,
            'line_total' => '99.90',
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $firstProduct->id,
            'order_id' => $orderId,
            'movement_type' => 'sale',
            'quantity_delta' => -2,
            'created_by' => $user->id,
        ]);
        $this->assertSame(3, $firstProduct->fresh()->stock_quantity);
        $this->assertSame(2, $secondProduct->fresh()->stock_quantity);
        $this->assertSame('converted', $cart->fresh()->status);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.items', []);
    }

    public function test_checkout_rejects_insufficient_stock_without_changes(): void
    {
        $user = User::factory()->create();
        $cart = $user->carts()->create(['status' => 'active']);
        $product = $this->createProduct(['stock_quantity' => 1]);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 2]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout', ['address' => $this->address()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertSame(1, $product->fresh()->stock_quantity);
        $this->assertSame('active', $cart->fresh()->status);
    }

    public function test_checkout_rejects_invalid_item_quantities(): void
    {
        $user = User::factory()->create();
        $cart = $user->carts()->create(['status' => 'active']);
        $product = $this->createProduct();
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 0]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout', ['address' => $this->address()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(5, $product->fresh()->stock_quantity);
        $this->assertSame('active', $cart->fresh()->status);
    }

    public function test_checkout_rejects_products_that_became_inactive(): void
    {
        $user = User::factory()->create();
        $cart = $user->carts()->create(['status' => 'active']);
        $product = $this->createProduct(['status' => 'inactive']);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout', ['address' => $this->address()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(5, $product->fresh()->stock_quantity);
        $this->assertSame('active', $cart->fresh()->status);
    }

    public function test_checkout_never_uses_another_users_cart(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherCart = $otherUser->carts()->create(['status' => 'active']);
        $product = $this->createProduct();
        $otherCart->items()->create(['product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout', [
                'address' => $this->address(),
                'user_id' => $otherUser->id,
                'cart_id' => $otherCart->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cart');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(5, $product->fresh()->stock_quantity);
        $this->assertSame('active', $otherCart->fresh()->status);
    }

    public function test_checkout_rolls_back_order_stock_and_movements_on_failure(): void
    {
        $user = User::factory()->create();
        $cart = $user->carts()->create(['status' => 'active']);
        $firstProduct = $this->createProduct(['sku' => 'CHECKOUT-ROLLBACK-1']);
        $secondProduct = $this->createProduct(['sku' => 'CHECKOUT-ROLLBACK-2']);
        $cart->items()->create(['product_id' => $firstProduct->id, 'quantity' => 1]);
        $cart->items()->create(['product_id' => $secondProduct->id, 'quantity' => 1]);
        $inventoryService = new class extends InventoryService
        {
            private int $decrements = 0;

            public function decreaseForOrder(
                Product $product,
                int $quantity,
                Order $order,
                User $user,
            ): void {
                parent::decreaseForOrder($product, $quantity, $order, $user);

                if (++$this->decrements === 2) {
                    throw new RuntimeException('Simulated inventory failure.');
                }
            }
        };
        $checkoutService = new CheckoutService($inventoryService);

        try {
            $checkoutService->checkout($user, $this->address());
            $this->fail('The simulated inventory failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated inventory failure.', $exception->getMessage());
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('order_items', 0);
            $this->assertDatabaseCount('order_addresses', 0);
            $this->assertDatabaseCount('inventory_movements', 0);
            $this->assertSame(5, $firstProduct->fresh()->stock_quantity);
            $this->assertSame(5, $secondProduct->fresh()->stock_quantity);
            $this->assertSame('active', $cart->fresh()->status);
        }
    }

    public function test_checkout_validates_delivery_address(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout', [
                'address' => $this->address(['cep' => 'invalid']),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address.cep');

        $this->assertDatabaseCount('orders', 0);
    }

    /** @return array<string, string> */
    private function address(array $overrides = []): array
    {
        return array_merge([
            'recipient_name' => 'Cliente de teste',
            'cep' => '01001-000',
            'street' => 'Praça da Sé',
            'number' => '100',
            'complement' => '',
            'neighborhood' => 'Sé',
            'city' => 'São Paulo',
            'state' => 'SP',
        ], $overrides);
    }

    private function createProduct(array $overrides = []): Product
    {
        static $sequence = 0;
        $sequence++;

        return Product::query()->create(array_merge([
            'sku' => 'CHECKOUT-TEST-'.$sequence,
            'name' => 'Produto de checkout',
            'slug' => 'produto-de-checkout-'.$sequence,
            'description' => 'Produto usado em teste isolado.',
            'price' => '49.95',
            'stock_quantity' => 5,
            'coloration' => 'colorido',
            'status' => 'active',
        ], $overrides));
    }
}
