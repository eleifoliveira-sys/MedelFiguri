<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CartApiTest extends TestCase
{
    use RefreshDatabase;

    private const GUEST_COOKIE = 'medelfiguri_guest_cart';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', 'http://localhost:8080');
        $this->withCredentials();
        config()->set('sanctum.stateful', ['localhost:8080', '127.0.0.1:8080']);
    }

    public function test_guest_cart_is_created_with_a_private_http_only_cookie(): void
    {
        $response = $this->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.total_quantity', 0)
            ->assertJsonPath('data.subtotal', '0.00')
            ->assertJsonMissingPath('data.guest_token');

        $cookie = $response->getCookie(self::GUEST_COOKIE);

        $this->assertNotNull($cookie);
        $this->assertSame(64, strlen($cookie->getValue()));
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertDatabaseCount('carts', 1);
    }

    public function test_guest_cart_persists_between_requests(): void
    {
        $firstResponse = $this->getJson('/api/cart')->assertOk();
        $cartId = $firstResponse->json('data.id');
        $token = $firstResponse->getCookie(self::GUEST_COOKIE)->getValue();

        $this->withCookie(self::GUEST_COOKIE, $token)
            ->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('data.id', $cartId);

        $this->assertDatabaseCount('carts', 1);
    }

    public function test_guest_can_add_an_active_product_and_receives_current_prices(): void
    {
        $product = $this->createProduct();

        $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => '0.01',
            'user_id' => 999,
        ])->assertOk()
            ->assertJsonPath('data.items.0.product.id', $product->id)
            ->assertJsonPath('data.items.0.product.name', $product->name)
            ->assertJsonPath('data.items.0.product.sku', $product->sku)
            ->assertJsonPath('data.items.0.product.unit_price', '49.95')
            ->assertJsonPath('data.items.0.subtotal', '99.90')
            ->assertJsonPath('data.subtotal', '99.90')
            ->assertJsonPath('data.total_quantity', 2)
            ->assertJsonMissingPath('data.guest_token')
            ->assertJsonMissingPath('data.user_id');

        $this->assertSame(5, $product->fresh()->stock_quantity);
    }

    public function test_cart_items_include_the_path_of_an_available_3d_model(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('renders/cart-figure.glb', 'fake 3d model');
        $product = $this->createProduct();
        $product->images()->create([
            'path' => 'renders/cart-figure.glb',
            'sort_order' => 1,
        ]);

        $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertOk()
            ->assertJsonPath('data.items.0.product.model_path', 'renders/cart-figure.glb');
    }

    public function test_adding_the_same_product_updates_the_existing_quantity(): void
    {
        $product = $this->createProduct();
        $firstResponse = $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'quantity' => 2,
        ])->assertOk();
        $token = $firstResponse->getCookie(self::GUEST_COOKIE)->getValue();

        $this->withCookie(self::GUEST_COOKIE, $token)
            ->postJson('/api/cart/items', [
                'product_id' => $product->id,
                'quantity' => 4,
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 4)
            ->assertJsonPath('data.total_quantity', 4);

        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_guest_can_update_item_quantity(): void
    {
        $product = $this->createProduct();
        $firstResponse = $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertOk();
        $itemId = $firstResponse->json('data.items.0.id');
        $token = $firstResponse->getCookie(self::GUEST_COOKIE)->getValue();

        $this->withCookie(self::GUEST_COOKIE, $token)
            ->patchJson("/api/cart/items/{$itemId}", ['quantity' => 3])
            ->assertOk()
            ->assertJsonPath('data.items.0.quantity', 3)
            ->assertJsonPath('data.items.0.subtotal', '149.85');
    }

    public function test_guest_can_remove_item_and_receives_updated_cart(): void
    {
        $product = $this->createProduct();
        $firstResponse = $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertOk();
        $itemId = $firstResponse->json('data.items.0.id');
        $token = $firstResponse->getCookie(self::GUEST_COOKIE)->getValue();

        $this->withCookie(self::GUEST_COOKIE, $token)
            ->deleteJson("/api/cart/items/{$itemId}")
            ->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.subtotal', '0.00');

        $this->assertDatabaseMissing('cart_items', ['id' => $itemId]);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock_quantity' => 5,
        ]);
    }

    public function test_empty_guest_cart_remains_available(): void
    {
        $response = $this->getJson('/api/cart')->assertOk();
        $token = $response->getCookie(self::GUEST_COOKIE)->getValue();

        $this->withCookie(self::GUEST_COOKIE, $token)
            ->getJson('/api/cart')
            ->assertOk()
            ->assertJsonCount(0, 'data.items')
            ->assertJsonPath('data.total_quantity', 0);
    }

    public function test_add_rejects_a_nonexistent_product(): void
    {
        $this->postJson('/api/cart/items', [
            'product_id' => 99999,
            'quantity' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('product_id');
    }

    public function test_add_rejects_an_inactive_product(): void
    {
        $product = $this->createProduct(['status' => 'inactive']);

        $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('product_id');
    }

    public function test_add_rejects_a_zero_quantity(): void
    {
        $product = $this->createProduct();

        $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'quantity' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');
    }

    public function test_add_rejects_a_negative_quantity(): void
    {
        $product = $this->createProduct();

        $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'quantity' => -1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');
    }

    public function test_add_rejects_quantity_above_current_stock(): void
    {
        $product = $this->createProduct(['stock_quantity' => 2]);

        $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'quantity' => 3,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');
    }

    public function test_guest_cannot_reuse_another_guests_cart_or_item(): void
    {
        $product = $this->createProduct();
        $firstResponse = $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertOk();
        $firstCartId = $firstResponse->json('data.id');
        $itemId = $firstResponse->json('data.items.0.id');

        $secondResponse = $this->withCookie(self::GUEST_COOKIE, 'a'.str_repeat('b', 63))
            ->getJson('/api/cart')
            ->assertOk();

        $this->assertNotSame($firstCartId, $secondResponse->json('data.id'));

        $otherToken = $secondResponse->getCookie(self::GUEST_COOKIE)->getValue();
        $this->withCookie(self::GUEST_COOKIE, $otherToken)
            ->patchJson("/api/cart/items/{$itemId}", ['quantity' => 3])
            ->assertNotFound();

        $this->withCookie(self::GUEST_COOKIE, $otherToken)
            ->deleteJson("/api/cart/items/{$itemId}")
            ->assertNotFound();
    }

    public function test_authenticated_user_gets_only_their_own_active_cart(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownedProduct = $this->createProduct(['sku' => 'CART-OWNED']);
        $otherProduct = $this->createProduct(['sku' => 'CART-OTHER']);
        $ownedCart = $owner->carts()->create(['status' => 'active']);
        $otherCart = $otherUser->carts()->create(['status' => 'active']);
        $ownedCart->items()->create(['product_id' => $ownedProduct->id, 'quantity' => 1]);
        $otherCart->items()->create(['product_id' => $otherProduct->id, 'quantity' => 1]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('data.id', $ownedCart->id)
            ->assertJsonPath('data.items.0.product.id', $ownedProduct->id)
            ->assertJsonMissing(['id' => $otherProduct->id]);
    }

    public function test_authenticated_user_gets_a_cart_when_none_exists(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.items', []);

        $this->assertDatabaseCount('carts', 1);
        $this->assertDatabaseHas('carts', [
            'user_id' => $user->id,
            'guest_token' => null,
            'status' => 'active',
        ]);
    }

    public function test_authenticated_user_cannot_modify_another_users_item(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $product = $this->createProduct();
        $otherCart = $otherUser->carts()->create(['status' => 'active']);
        $otherItem = $otherCart->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/cart/items/{$otherItem->id}", ['quantity' => 2])
            ->assertNotFound();

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/cart/items/{$otherItem->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('cart_items', [
            'id' => $otherItem->id,
            'quantity' => 1,
        ]);
    }

    public function test_authenticated_user_creates_only_one_active_cart(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/cart')
            ->assertOk();
        $firstCartId = $this->getJson('/api/cart')->json('data.id');
        $secondCartId = $this->getJson('/api/cart')->json('data.id');

        $this->assertSame($firstCartId, $secondCartId);
        $this->assertDatabaseCount('carts', 1);
    }

    public function test_cart_mutation_responses_are_json(): void
    {
        $product = $this->createProduct();

        $added = $this->postJson('/api/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertOk();
        $this->assertJsonResponse($added);

        $token = $added->getCookie(self::GUEST_COOKIE)->getValue();
        $itemId = $added->json('data.items.0.id');
        $updated = $this->withCookie(self::GUEST_COOKIE, $token)
            ->patchJson("/api/cart/items/{$itemId}", ['quantity' => 2])
            ->assertOk();
        $this->assertJsonResponse($updated);

        $removed = $this->withCookie(self::GUEST_COOKIE, $token)
            ->deleteJson("/api/cart/items/{$itemId}")
            ->assertOk();
        $this->assertJsonResponse($removed);
    }

    public function test_cart_money_uses_exact_decimal_arithmetic_for_large_totals(): void
    {
        $stock = 2147483647;
        $firstProduct = $this->createProduct([
            'sku' => 'CART-LARGE-1',
            'price' => '99999999.99',
            'stock_quantity' => $stock,
        ]);
        $secondProduct = $this->createProduct([
            'sku' => 'CART-LARGE-2',
            'price' => '99999999.99',
            'stock_quantity' => $stock,
        ]);

        $firstResponse = $this->postJson('/api/cart/items', [
            'product_id' => $firstProduct->id,
            'quantity' => $stock,
        ])->assertOk();
        $token = $firstResponse->getCookie(self::GUEST_COOKIE)->getValue();

        $this->withCookie(self::GUEST_COOKIE, $token)
            ->postJson('/api/cart/items', [
                'product_id' => $secondProduct->id,
                'quantity' => $stock,
            ])
            ->assertOk()
            ->assertJsonPath('data.items.0.subtotal', '214748364678525163.53')
            ->assertJsonPath('data.subtotal', '429496729357050327.06');
    }

    private function createProduct(array $overrides = []): Product
    {
        static $sequence = 0;
        $sequence++;

        return Product::query()->create(array_merge([
            'sku' => 'CART-TEST-'.$sequence,
            'name' => 'Produto de carrinho',
            'slug' => 'produto-de-carrinho-'.$sequence,
            'description' => 'Produto usado em teste isolado.',
            'price' => '49.95',
            'stock_quantity' => 5,
            'coloration' => 'colorido',
            'status' => 'active',
        ], $overrides));
    }

    private function assertJsonResponse(TestResponse $response): void
    {
        $this->assertStringContainsString(
            'application/json',
            (string) $response->headers->get('Content-Type'),
        );
    }
}
