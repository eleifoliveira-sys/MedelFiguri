<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Product;
use App\Models\User;
use App\Services\CartService;
use App\Support\GuestCartCookie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CartMergeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sanctum.stateful', ['localhost:8080', '127.0.0.1:8080']);
        $this->withHeader('Origin', 'http://localhost:8080');
        $this->withCredentials();
    }

    public function test_login_without_a_guest_cart_preserves_existing_contract(): void
    {
        $user = $this->createUser();

        $response = $this->login($user);

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('cart_merge.status', 'no_guest_cart')
            ->assertJsonMissingPath('data.password');

        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_login_merges_guest_items_into_a_new_account_cart(): void
    {
        $user = $this->createUser();
        $guestCart = $this->createGuestCart();
        $product = $this->createProduct();
        $guestCart->items()->create(['product_id' => $product->id, 'quantity' => 2]);

        $response = $this->login($user, $guestCart->guest_token);

        $response->assertOk()
            ->assertJsonPath('cart_merge.status', 'merged')
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonMissingPath('cart_merge.guest_token');

        $accountCart = $user->carts()->where('status', 'active')->firstOrFail();
        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $accountCart->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);
        $this->assertSame('merged', $guestCart->fresh()->status);
        $this->assertSame($accountCart->id, $guestCart->fresh()->merged_into_id);
        $this->assertSame(0, $guestCart->items()->count());
    }

    public function test_login_sums_shared_products_and_preserves_account_only_products(): void
    {
        $user = $this->createUser();
        $guestCart = $this->createGuestCart();
        $shared = $this->createProduct(['sku' => 'MERGE-SHARED']);
        $guestOnly = $this->createProduct(['sku' => 'MERGE-GUEST']);
        $accountOnly = $this->createProduct(['sku' => 'MERGE-ACCOUNT']);
        $accountCart = $user->carts()->create(['status' => 'active']);
        $guestCart->items()->create(['product_id' => $shared->id, 'quantity' => 2]);
        $guestCart->items()->create(['product_id' => $guestOnly->id, 'quantity' => 1]);
        $accountCart->items()->create(['product_id' => $shared->id, 'quantity' => 1]);
        $accountCart->items()->create(['product_id' => $accountOnly->id, 'quantity' => 3]);

        $this->login($user, $guestCart->guest_token)
            ->assertOk()
            ->assertJsonPath('cart_merge.status', 'merged');

        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $accountCart->id,
            'product_id' => $shared->id,
            'quantity' => 3,
        ]);
        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $accountCart->id,
            'product_id' => $guestOnly->id,
            'quantity' => 1,
        ]);
        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $accountCart->id,
            'product_id' => $accountOnly->id,
            'quantity' => 3,
        ]);
        $this->assertSame(1, $accountCart->items()->where('product_id', $shared->id)->count());
        $this->assertDatabaseCount('cart_items', 3);
        $this->assertSame(5, $shared->fresh()->stock_quantity);
        $this->assertSame(5, $guestOnly->fresh()->stock_quantity);
        $this->assertSame(5, $accountOnly->fresh()->stock_quantity);
    }

    public function test_login_with_an_empty_guest_cart_marks_it_merged(): void
    {
        $user = $this->createUser();
        $guestCart = $this->createGuestCart();

        $this->login($user, $guestCart->guest_token)
            ->assertOk()
            ->assertJsonPath('cart_merge.status', 'merged');

        $this->assertSame('merged', $guestCart->fresh()->status);
        $this->assertNotNull($guestCart->fresh()->merged_into_id);
        $this->assertDatabaseCount('carts', 2);
    }

    public function test_login_ignores_an_invalid_guest_token(): void
    {
        $user = $this->createUser();

        $this->login($user, 'invalid')
            ->assertOk()
            ->assertJsonPath('cart_merge.status', 'no_guest_cart');

        $this->assertDatabaseCount('carts', 0);
    }

    public function test_inactive_guest_product_returns_attention_without_changes(): void
    {
        $user = $this->createUser();
        $guestCart = $this->createGuestCart();
        $inactive = $this->createProduct([
            'sku' => 'MERGE-INACTIVE',
            'status' => 'inactive',
        ]);
        $guestItem = $guestCart->items()->create([
            'product_id' => $inactive->id,
            'quantity' => 1,
        ]);

        $response = $this->login($user, $guestCart->guest_token)
            ->assertOk()
            ->assertJsonPath('cart_merge.status', 'needs_attention')
            ->assertJsonPath('cart_merge.conflicts.0.product_id', $inactive->id)
            ->assertJsonPath('cart_merge.conflicts.0.reason', 'product_unavailable');

        $this->assertSame($user->id, $response->json('data.id'));
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->assertSame('active', $guestCart->fresh()->status);
        $this->assertNull($guestCart->fresh()->merged_into_id);
        $this->assertDatabaseHas('cart_items', ['id' => $guestItem->id]);
        $this->assertDatabaseCount('carts', 1);
        $this->assertNull($response->getCookie(GuestCartCookie::NAME));
    }

    public function test_quantity_conflict_rolls_back_all_changes_and_preserves_cookie(): void
    {
        $user = $this->createUser();
        $guestCart = $this->createGuestCart();
        $accountCart = $user->carts()->create(['status' => 'active']);
        $valid = $this->createProduct(['sku' => 'MERGE-VALID']);
        $conflicting = $this->createProduct([
            'sku' => 'MERGE-CONFLICT',
            'stock_quantity' => 2,
        ]);
        $accountItem = $accountCart->items()->create([
            'product_id' => $valid->id,
            'quantity' => 1,
        ]);
        $accountConflictItem = $accountCart->items()->create([
            'product_id' => $conflicting->id,
            'quantity' => 1,
        ]);
        $guestValidItem = $guestCart->items()->create([
            'product_id' => $valid->id,
            'quantity' => 1,
        ]);
        $guestConflictItem = $guestCart->items()->create([
            'product_id' => $conflicting->id,
            'quantity' => 2,
        ]);

        $response = $this->login($user, $guestCart->guest_token)
            ->assertOk()
            ->assertJsonPath('cart_merge.status', 'needs_attention')
            ->assertJsonPath('cart_merge.conflicts.0.product_id', $conflicting->id)
            ->assertJsonPath('cart_merge.conflicts.0.requested_quantity', 3)
            ->assertJsonPath('cart_merge.conflicts.0.available_quantity', 2);

        $this->assertNull($response->getCookie(GuestCartCookie::NAME));
        $this->assertSame('active', $guestCart->fresh()->status);
        $this->assertNull($guestCart->fresh()->merged_into_id);
        $this->assertDatabaseHas('cart_items', [
            'id' => $accountItem->id,
            'quantity' => 1,
        ]);
        $this->assertDatabaseHas('cart_items', [
            'id' => $guestValidItem->id,
            'quantity' => 1,
        ]);
        $this->assertDatabaseHas('cart_items', [
            'id' => $guestConflictItem->id,
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('cart_items', [
            'id' => $accountConflictItem->id,
            'quantity' => 1,
        ]);
        $this->assertSame(5, $valid->fresh()->stock_quantity);
        $this->assertSame(2, $conflicting->fresh()->stock_quantity);
    }

    public function test_shared_product_sum_above_stock_is_reported_as_conflict(): void
    {
        $user = $this->createUser();
        $guestCart = $this->createGuestCart();
        $product = $this->createProduct(['stock_quantity' => 2]);
        $accountCart = $user->carts()->create(['status' => 'active']);
        $guestCart->items()->create(['product_id' => $product->id, 'quantity' => 1]);
        $accountCart->items()->create(['product_id' => $product->id, 'quantity' => 2]);

        $this->login($user, $guestCart->guest_token)
            ->assertOk()
            ->assertJsonPath('cart_merge.status', 'needs_attention')
            ->assertJsonPath('cart_merge.conflicts.0.requested_quantity', 3)
            ->assertJsonPath('cart_merge.conflicts.0.reason', 'insufficient_stock');

        $this->assertSame(1, $guestCart->items()->firstOrFail()->quantity);
        $this->assertSame(2, $accountCart->items()->firstOrFail()->quantity);
        $this->assertSame('active', $guestCart->fresh()->status);
    }

    public function test_conflict_does_not_create_an_account_cart_before_validation(): void
    {
        $user = $this->createUser();
        $guestCart = $this->createGuestCart();
        $product = $this->createProduct(['status' => 'inactive']);
        $guestCart->items()->create(['product_id' => $product->id, 'quantity' => 1]);

        $this->login($user, $guestCart->guest_token)
            ->assertOk()
            ->assertJsonPath('cart_merge.status', 'needs_attention');

        $this->assertSame(1, Cart::query()->count());
        $this->assertSame(0, $user->carts()->count());
        $this->assertSame('active', $guestCart->fresh()->status);
    }

    public function test_successful_merge_invalidates_only_guest_cart_cookie(): void
    {
        $user = $this->createUser();
        $guestCart = $this->createGuestCart();
        $guestCart->items()->create([
            'product_id' => $this->createProduct()->id,
            'quantity' => 1,
        ]);

        $response = $this->login($user, $guestCart->guest_token)
            ->assertOk()
            ->assertJsonPath('cart_merge.status', 'merged');

        $clearedCookie = $response->getCookie(GuestCartCookie::NAME);
        $this->assertNotNull($clearedCookie);
        $this->assertSame('', $clearedCookie->getValue());
        $this->assertTrue($clearedCookie->isHttpOnly());
        $this->assertSame('lax', $clearedCookie->getSameSite());
        $this->assertTrue($clearedCookie->isCleared());
        $this->assertNotNull($response->getCookie(config('session.cookie')));
    }

    public function test_repeating_login_after_merge_does_not_add_quantities_again(): void
    {
        $user = $this->createUser();
        $guestCart = $this->createGuestCart();
        $product = $this->createProduct();
        $guestCart->items()->create(['product_id' => $product->id, 'quantity' => 2]);

        $this->login($user, $guestCart->guest_token)
            ->assertOk()
            ->assertJsonPath('cart_merge.status', 'merged');
        $this->postJson('/api/logout')->assertOk();

        $this->login($user, $guestCart->guest_token)
            ->assertOk()
            ->assertJsonPath('cart_merge.status', 'merged')
            ->assertJsonPath('cart_merge.already_merged', true);

        $accountCart = $user->carts()->where('status', 'active')->firstOrFail();
        $this->assertSame(2, $accountCart->items()->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(1, $accountCart->items()->where('product_id', $product->id)->count());
    }

    public function test_guest_cart_already_merged_to_another_user_is_not_exposed(): void
    {
        $originalUser = $this->createUser();
        $otherUser = $this->createUser();
        $guestCart = $this->createGuestCart();
        $guestCart->items()->create([
            'product_id' => $this->createProduct()->id,
            'quantity' => 1,
        ]);

        $this->login($originalUser, $guestCart->guest_token)
            ->assertOk()
            ->assertJsonPath('cart_merge.status', 'merged');
        $this->postJson('/api/logout')->assertOk();

        $this->login($otherUser, $guestCart->guest_token)
            ->assertOk()
            ->assertJsonPath('cart_merge.status', 'no_guest_cart');

        $this->assertSame($originalUser->id, $guestCart->fresh()->mergedInto->user_id);
        $this->assertSame(0, $otherUser->carts()->count());
    }

    public function test_unexpected_merge_failure_does_not_fail_successful_login(): void
    {
        $user = $this->createUser();
        $service = $this->mock(CartService::class);
        $service->shouldReceive('mergeGuestCart')
            ->once()
            ->andThrow(new RuntimeException('Unexpected merge error'));

        $guestToken = Str::random(64);
        $response = $this->login($user, $guestToken);

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('cart_merge.status', 'needs_attention')
            ->assertJsonPath('cart_merge.reason', 'merge_failed');

        $this->assertNull($response->getCookie(GuestCartCookie::NAME));
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    private function login(User $user, ?string $guestToken = null)
    {
        if ($guestToken !== null) {
            $this->withCookie(GuestCartCookie::NAME, $guestToken);
        }

        return $this->postJson('/api/login', [
            'login' => $user->username,
            'password' => 'SecurePass123!',
        ]);
    }

    private function createUser(): User
    {
        return User::factory()->create([
            'password' => Hash::make('SecurePass123!'),
        ]);
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
            'sku' => 'MERGE-TEST-'.$sequence,
            'name' => 'Produto para fusão',
            'slug' => 'produto-para-fusao-'.$sequence,
            'description' => 'Produto usado em teste isolado.',
            'price' => '49.95',
            'stock_quantity' => 5,
            'coloration' => 'colorido',
            'status' => 'active',
        ], $overrides));
    }
}
