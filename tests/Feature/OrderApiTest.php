<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sanctum.stateful', ['localhost:8080', '127.0.0.1:8080']);
        $this->withHeader('Origin', 'http://localhost:8080');
        $this->withCredentials();
    }

    public function test_order_endpoints_require_authentication(): void
    {
        $this->getJson('/api/orders')->assertUnauthorized();
        $this->getJson('/api/orders/1')->assertUnauthorized();
        $this->postJson('/api/orders/1/confirm-payment')->assertUnauthorized();
        $this->postJson('/api/orders/1/cancel')->assertUnauthorized();
    }

    public function test_customer_can_list_only_their_own_orders(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $order = $this->createOrder($user);
        $this->createOrder($otherUser);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.items.0.product_name', 'Snapshot histórico');
    }

    public function test_customer_can_read_own_order_but_not_another_customer_order(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $order = $this->createOrder($user);
        $otherOrder = $this->createOrder($otherUser);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonPath('data.items.0.unit_price', '18.50')
            ->assertJsonPath('data.items.0.product_sku', 'HISTORICAL-SKU');

        $this->getJson('/api/orders/999999')->assertNotFound();
        $this->getJson("/api/orders/{$otherOrder->id}")->assertNotFound();
    }

    public function test_payment_confirmation_is_simulated_and_does_not_change_stock(): void
    {
        $user = User::factory()->create();
        $order = $this->createOrder($user);
        $product = $this->createProduct();
        $order->items()->update(['product_id' => $product->id]);
        $product->update(['stock_quantity' => 3]);
        $this->createMovement($order, $product, -2, 'sale');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/orders/{$order->id}/confirm-payment")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment_method', 'simulated')
            ->assertJsonPath('payment.simulated', true)
            ->assertJsonPath('payment.already_confirmed', false)
            ->assertJsonPath('payment.message', 'Pagamento simulado — nenhuma cobrança real foi realizada.');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'paid',
            'payment_method' => 'simulated',
        ]);
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame(3, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseMissing('orders', ['payment_method' => 'card']);
    }

    public function test_repeated_payment_confirmation_is_idempotent(): void
    {
        $user = User::factory()->create();
        $order = $this->createOrder($user, ['status' => 'pending']);

        $first = $this->actingAs($user, 'sanctum')
            ->postJson("/api/orders/{$order->id}/confirm-payment")
            ->assertOk()
            ->json('data.paid_at');

        $this->postJson("/api/orders/{$order->id}/confirm-payment")
            ->assertOk()
            ->assertJsonPath('payment.already_confirmed', true)
            ->assertJsonPath('data.paid_at', $first);

        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_payment_confirmation_rejects_another_users_order(): void
    {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $order = $this->createOrder($owner);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/orders/{$order->id}/confirm-payment")
            ->assertNotFound();

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_payment_confirmation_rejects_cancelled_and_invalid_states(): void
    {
        $user = User::factory()->create();
        $cancelled = $this->createOrder($user, ['status' => 'cancelled']);
        $processing = $this->createOrder($user, ['status' => 'processing']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/orders/{$cancelled->id}/confirm-payment")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order');

        $this->postJson("/api/orders/{$processing->id}/confirm-payment")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order');
    }

    public function test_simulated_payment_rejects_card_information(): void
    {
        $user = User::factory()->create();
        $order = $this->createOrder($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/orders/{$order->id}/confirm-payment", [
                'card_number' => '4111111111111111',
                'cvv' => '123',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_number', 'cvv']);

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_cancellation_restores_stock_once_for_an_order_with_sale_movement(): void
    {
        $user = User::factory()->create();
        $order = $this->createOrder($user, ['status' => 'paid']);
        $product = $this->createProduct(['stock_quantity' => 3]);
        $order->items()->update(['product_id' => $product->id, 'quantity' => 2]);
        $this->createMovement($order, $product, -2, 'sale');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('cancellation.inventory_restored', true);

        $this->postJson("/api/orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('cancellation.already_cancelled', true);

        $this->assertSame(5, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertDatabaseHas('inventory_movements', [
            'order_id' => $order->id,
            'movement_type' => 'return',
            'quantity_delta' => 2,
            'created_by' => $user->id,
        ]);
    }

    public function test_cancellation_without_checkout_movement_does_not_invent_a_return(): void
    {
        $user = User::factory()->create();
        $order = $this->createOrder($user, ['status' => 'pending']);
        $product = $this->createProduct(['stock_quantity' => 3]);
        $order->items()->update(['product_id' => $product->id, 'quantity' => 2]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('cancellation.inventory_restored', false);

        $this->assertSame(3, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_cancellation_rejects_processing_shipped_and_delivered_orders(): void
    {
        $user = User::factory()->create();

        foreach (['processing', 'shipped', 'delivered'] as $status) {
            $order = $this->createOrder($user, ['status' => $status]);
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/orders/{$order->id}/cancel")
                ->assertUnprocessable()
                ->assertJsonValidationErrors('order');
        }
    }

    public function test_cancellation_rolls_back_order_stock_and_movements_on_failure(): void
    {
        $user = User::factory()->create();
        $order = $this->createOrder($user, ['status' => 'paid']);
        $product = $this->createProduct(['stock_quantity' => 3]);
        $order->items()->update(['product_id' => $product->id, 'quantity' => 2]);
        $this->createMovement($order, $product, -2, 'sale');
        $inventory = new class extends InventoryService
        {
            public function returnForCancellation(Product $product, int $quantity, Order $order, User $user): void
            {
                parent::returnForCancellation($product, $quantity, $order, $user);
                throw new RuntimeException('Simulated stock return failure.');
            }
        };

        try {
            (new OrderService($inventory))->cancel($user, $order->id);
            $this->fail('The simulated stock return failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated stock return failure.', $exception->getMessage());
        }

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(3, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    private function createOrder(User $user, array $overrides = []): Order
    {
        static $sequence = 0;
        $sequence++;

        $order = $user->orders()->create(array_merge([
            'order_number' => 'MF-TEST-ORDER-'.$sequence,
            'status' => 'pending',
            'subtotal' => '18.50',
            'shipping_cost' => '0.00',
            'discount_amount' => '0.00',
            'total' => '18.50',
            'payment_method' => 'pending',
        ], $overrides));

        $order->items()->create([
            'product_name' => 'Snapshot histórico',
            'product_sku' => 'HISTORICAL-SKU',
            'unit_price' => '18.50',
            'quantity' => 1,
            'line_total' => '18.50',
        ]);
        $order->address()->create([
            'recipient_name' => 'Destinatário histórico',
            'cep' => '01001-000',
            'street' => 'Rua histórica',
            'number' => '1',
            'complement' => null,
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
        ]);

        return $order;
    }

    private function createProduct(array $overrides = []): Product
    {
        static $sequence = 0;
        $sequence++;

        return Product::query()->create(array_merge([
            'sku' => 'ORDER-TEST-'.$sequence,
            'name' => 'Produto de teste de pedido',
            'slug' => 'produto-pedido-teste-'.$sequence,
            'description' => 'Produto usado em teste isolado.',
            'price' => '18.50',
            'stock_quantity' => 5,
            'coloration' => 'colorido',
            'status' => 'active',
        ], $overrides));
    }

    private function createMovement(Order $order, Product $product, int $delta, string $type): void
    {
        InventoryMovement::query()->create([
            'product_id' => $product->id,
            'order_id' => $order->id,
            'movement_type' => $type,
            'quantity_delta' => $delta,
            'reason' => 'Movimentação de teste.',
        ]);
    }
}
