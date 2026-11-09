<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Product;
use App\Models\Users\User;
use App\Support\GuestCartCookie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GuestCartRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sanctum.stateful', ['localhost:8080', '127.0.0.1:8080']);
        $this->withHeader('Origin', 'http://localhost:8080');
        $this->withCredentials();
    }

    public function test_authenticated_user_can_read_the_guest_cart_from_its_cookie(): void
    {
        $user = User::factory()->create();
        $guestCart = $this->createGuestCart();
        $product = $this->createProduct();
        $guestCart->items()->create(['product_id' => $product->id, 'quantity' => 2]);

        $this->actingAs($user, 'sanctum')
            ->withCookie(GuestCartCookie::NAME, $guestCart->guest_token)
            ->getJson('/api/cart/guest')
            ->assertOk()
            ->assertJsonPath('data.id', $guestCart->id)
            ->assertJsonPath('data.total_quantity', 2)
            ->assertJsonPath('data.items.0.product.is_available', true)
            ->assertJsonPath('data.items.0.product.available_quantity', 5)
            ->assertJsonMissingPath('data.guest_token');
    }

    public function test_logged_in_user_can_recover_guest_cart_after_merge_attention(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('SecurePass123!'),
        ]);
        $guestCart = $this->createGuestCart();
        $accountCart = $user->carts()->create(['status' => 'active']);
        $product = $this->createProduct(['stock_quantity' => 2]);
        $accountCart->items()->create(['product_id' => $product->id, 'quantity' => 1]);
        $guestItem = $guestCart->items()->create(['product_id' => $product->id, 'quantity' => 2]);

        $login = $this->withCookie(GuestCartCookie::NAME, $guestCart->guest_token)
            ->postJson('/api/login', [
                'login' => $user->username,
                'password' => 'SecurePass123!',
            ])
            ->assertOk()
            ->assertJsonPath('cart_merge.status', 'needs_attention');

        $this->assertNull($login->getCookie(GuestCartCookie::NAME));

        $this->withCookie(GuestCartCookie::NAME, $guestCart->guest_token)
            ->getJson('/api/cart/guest')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $guestItem->id);

        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_authenticated_user_can_reduce_a_guest_item_after_login(): void
    {
        $user = User::factory()->create();
        $guestCart = $this->createGuestCart();
        $product = $this->createProduct(['stock_quantity' => 2]);
        $item = $guestCart->items()->create(['product_id' => $product->id, 'quantity' => 2]);

        $this->actingAs($user, 'sanctum')
            ->withCookie(GuestCartCookie::NAME, $guestCart->guest_token)
            ->patchJson("/api/cart/guest/items/{$item->id}", ['quantity' => 1])
            ->assertOk()
            ->assertJsonPath('data.items.0.quantity', 1)
            ->assertJsonPath('data.total_quantity', 1);

        $this->assertSame(2, $product->fresh()->stock_quantity);
    }

    public function test_authenticated_user_can_remove_an_unavailable_guest_item(): void
    {
        $user = User::factory()->create();
        $guestCart = $this->createGuestCart();
        $product = $this->createProduct(['status' => 'inactive']);
        $item = $guestCart->items()->create(['product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($user, 'sanctum')
            ->withCookie(GuestCartCookie::NAME, $guestCart->guest_token)
            ->deleteJson("/api/cart/guest/items/{$item->id}")
            ->assertOk()
            ->assertJsonPath('data.items', []);

        $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);
        $this->assertSame(5, $product->fresh()->stock_quantity);
    }

    public function test_guest_cart_operations_reject_an_item_outside_the_cookie_cart(): void
    {
        $user = User::factory()->create();
        $firstGuestCart = $this->createGuestCart();
        $otherGuestCart = $this->createGuestCart();
        $product = $this->createProduct();
        $item = $otherGuestCart->items()->create(['product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($user, 'sanctum')
            ->withCookie(GuestCartCookie::NAME, $firstGuestCart->guest_token)
            ->patchJson("/api/cart/guest/items/{$item->id}", ['quantity' => 2])
            ->assertNotFound();

        $this->actingAs($user, 'sanctum')
            ->withCookie(GuestCartCookie::NAME, $firstGuestCart->guest_token)
            ->deleteJson("/api/cart/guest/items/{$item->id}")
            ->assertNotFound();

        $this->assertSame(1, $item->fresh()->quantity);
    }

    public function test_guest_cart_routes_reject_missing_or_invalid_cookies(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/cart/guest')
            ->assertNotFound();

        $this->actingAs($user, 'sanctum')
            ->withCookie(GuestCartCookie::NAME, 'invalid-token')
            ->getJson('/api/cart/guest')
            ->assertNotFound();

        $this->assertDatabaseCount('carts', 0);
    }

    public function test_guest_cart_recovery_routes_require_authentication(): void
    {
        $this->getJson('/api/cart/guest')->assertUnauthorized();
        $this->postJson('/api/cart/merge-guest')->assertUnauthorized();
    }

    public function test_merge_retry_preserves_cart_on_conflict_then_merges_once_after_correction(): void
    {
        $user = User::factory()->create();
        $guestCart = $this->createGuestCart();
        $accountCart = $user->carts()->create(['status' => 'active']);
        $product = $this->createProduct(['stock_quantity' => 2]);
        $accountCart->items()->create(['product_id' => $product->id, 'quantity' => 1]);
        $guestItem = $guestCart->items()->create(['product_id' => $product->id, 'quantity' => 2]);
        $token = $guestCart->guest_token;

        $failedMerge = $this->actingAs($user, 'sanctum')
            ->withCookie(GuestCartCookie::NAME, $token)
            ->postJson('/api/cart/merge-guest')
            ->assertOk()
            ->assertJsonPath('data.status', 'needs_attention')
            ->assertJsonPath('data.conflicts.0.requested_quantity', 3);

        $this->assertNull($failedMerge->getCookie(GuestCartCookie::NAME));
        $this->assertSame('active', $guestCart->fresh()->status);
        $this->assertSame(2, $guestItem->fresh()->quantity);
        $this->assertSame(1, $accountCart->items()->where('product_id', $product->id)->value('quantity'));

        $this->actingAs($user, 'sanctum')
            ->withCookie(GuestCartCookie::NAME, $token)
            ->patchJson("/api/cart/guest/items/{$guestItem->id}", ['quantity' => 1])
            ->assertOk();

        $merged = $this->actingAs($user, 'sanctum')
            ->withCookie(GuestCartCookie::NAME, $token)
            ->postJson('/api/cart/merge-guest')
            ->assertOk()
            ->assertJsonPath('data.status', 'merged')
            ->assertJsonPath('data.merged_quantity', 1);

        $clearedCookie = $merged->getCookie(GuestCartCookie::NAME);
        $this->assertNotNull($clearedCookie);
        $this->assertSame('', $clearedCookie->getValue());
        $this->assertSame('merged', $guestCart->fresh()->status);
        $this->assertSame(2, $accountCart->items()->where('product_id', $product->id)->value('quantity'));

        $this->actingAs($user, 'sanctum')
            ->withCookie(GuestCartCookie::NAME, $token)
            ->postJson('/api/cart/merge-guest')
            ->assertOk()
            ->assertJsonPath('data.status', 'merged')
            ->assertJsonPath('data.already_merged', true);

        $this->assertSame(2, $accountCart->items()->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(2, $product->fresh()->stock_quantity);
    }

    private function createGuestCart(): Cart
    {
        return Cart::query()->create([
            'guest_token' => Str::random(64),
            'status' => 'active',
        ]);
    }

    private function createProduct(array $overrides = []): Product
    {
        static $sequence = 0;
        $sequence++;

        return Product::query()->create(array_merge([
            'sku' => 'RECOVERY-TEST-'.$sequence,
            'name' => 'Produto para recuperação do carrinho',
            'slug' => 'produto-recuperacao-'.$sequence,
            'description' => 'Produto usado em teste isolado.',
            'price' => '49.95',
            'stock_quantity' => 5,
            'coloration' => 'colorido',
            'status' => 'active',
        ], $overrides));
    }
}
