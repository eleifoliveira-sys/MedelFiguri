<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sanctum.stateful', ['localhost:8080', '127.0.0.1:8080']);
        $this->withHeader('Origin', 'http://localhost:8080');
        $this->withCredentials();
        Storage::fake('public');
    }

    public function test_admin_routes_require_authentication_and_admin_role(): void
    {
        $this->getJson('/api/admin/products')->assertUnauthorized();

        $client = User::factory()->create();
        $this->actingAs($client, 'sanctum')
            ->getJson('/api/admin/products')
            ->assertForbidden();
    }

    public function test_admin_list_responses_include_pagination_totals_for_dashboard_stats(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/products')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson('/api/admin/categories')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson('/api/admin/orders')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_admin_can_create_update_and_archive_products_safely(): void
    {
        $admin = $this->createAdmin();
        $category = $this->createCategory();
        $payload = $this->productData(['category_ids' => [$category->id], 'stock_quantity' => 4]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/products', $payload)
            ->assertCreated()
            ->assertJsonPath('data.stock_quantity', 4)
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.categories.0.id', $category->id);
        $productId = $response->json('data.id');

        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $productId,
            'movement_type' => 'adjustment',
            'quantity_delta' => 4,
            'created_by' => $admin->id,
        ]);

        $this->patchJson("/api/admin/products/{$productId}", [
            'name' => 'Produto atualizado',
            'stock_quantity' => 900,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('stock_quantity');

        $this->patchJson("/api/admin/products/{$productId}", [
            'name' => 'Produto atualizado',
            'price' => '25.90',
            'status' => 'active',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Produto atualizado')
            ->assertJsonPath('data.price', '25.90')
            ->assertJsonPath('data.stock_quantity', 4)
            ->assertJsonPath('data.status', 'inactive');

        $this->deleteJson("/api/admin/products/{$productId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('products', ['id' => $productId, 'stock_quantity' => 4]);
    }

    public function test_product_validation_rejects_duplicate_keys_and_invalid_values(): void
    {
        $admin = $this->createAdmin();
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/products', $this->productData([
                'price' => '-2',
                'stock_quantity' => -1,
                'sku' => 'has spaces',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price', 'stock_quantity']);
    }

    public function test_admin_can_manage_and_archive_categories_without_deleting_them(): void
    {
        $admin = $this->createAdmin();
        $parent = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/categories', [
                'name' => 'Categoria teste',
                'slug' => 'categoria-teste-admin',
            ])
            ->assertCreated()
            ->json('data');
        $child = $this->postJson('/api/admin/categories', [
            'name' => 'Subcategoria teste',
            'slug' => 'subcategoria-teste-admin',
            'parent_id' => $parent['id'],
        ])->assertCreated()->json('data');

        $this->patchJson("/api/admin/categories/{$parent['id']}", [
            'parent_id' => $child['id'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        $this->deleteJson("/api/admin/categories/{$child['id']}")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('categories', ['id' => $child['id'], 'is_active' => false]);
    }

    public function test_admin_can_upload_and_remove_validated_product_image(): void
    {
        $admin = $this->createAdmin();
        $product = $this->createProduct();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true);

        $image = $this->actingAs($admin, 'sanctum')
            ->post('/api/admin/products/'.$product->id.'/images', [
                'image' => UploadedFile::fake()->createWithContent('figure.png', $png),
                'alt_text' => 'Imagem de teste',
                'is_primary' => true,
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data');

        $this->assertSame('/api/storage/'.$image['path'], $image['url']);
        Storage::disk('public')->assertExists($image['path']);
        $this->deleteJson("/api/admin/products/{$product->id}/images/{$image['id']}")
            ->assertOk();
        Storage::disk('public')->assertMissing($image['path']);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => 'inactive']);
    }

    public function test_removing_the_last_3d_model_deactivates_the_product(): void
    {
        $admin = $this->createAdmin();
        $product = $this->createProduct();
        Storage::disk('public')->put('renders/figure.glb', 'fake 3d model');
        $image = $product->images()->create([
            'path' => 'renders/figure.glb',
            'alt_text' => 'Modelo 3D de teste',
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/products/{$product->id}/images/{$image->id}")
            ->assertOk();

        Storage::disk('public')->assertMissing('renders/figure.glb');
        $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => 'inactive']);
    }

    public function test_admin_stock_adjustments_are_transactional_and_recorded(): void
    {
        $admin = $this->createAdmin();
        $product = $this->createProduct(['stock_quantity' => 3]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/products/{$product->id}/stock-adjustments", [
                'quantity_delta' => -2,
                'reason' => 'Correção de inventário.',
            ])
            ->assertOk()
            ->assertJsonPath('data.stock_quantity', 1);

        $this->postJson("/api/admin/products/{$product->id}/stock-adjustments", [
            'quantity_delta' => -2,
            'reason' => 'Ajuste inválido.',
        ])->assertUnprocessable();

        $this->getJson('/api/admin/inventory/movements')
            ->assertOk()
            ->assertJsonPath('data.0.movement_type', 'adjustment')
            ->assertJsonPath('data.0.quantity_delta', -2);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertSame(1, $product->fresh()->stock_quantity);
    }

    public function test_admin_can_advance_order_only_through_allowed_states(): void
    {
        $admin = $this->createAdmin();
        $customer = User::factory()->create();
        $order = $customer->orders()->create([
            'order_number' => 'MF-ADMIN-ORDER',
            'status' => 'paid',
            'subtotal' => '20.00',
            'shipping_cost' => '0.00',
            'discount_amount' => '0.00',
            'total' => '20.00',
            'payment_method' => 'simulated',
            'paid_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'shipped'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'processing'])
            ->assertOk()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.customer.email', $customer->email);

        $this->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'shipped'])
            ->assertOk()
            ->assertJsonPath('data.status', 'shipped');

        $this->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'cancelled'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    private function createAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::query()->firstOrCreate(
            ['slug' => 'admin'],
            ['name' => 'Administrador', 'description' => 'Papel de teste'],
        );
        $user->roles()->attach($role);

        return $user;
    }

    private function createCategory(): Category
    {
        static $sequence = 0;
        $sequence++;

        return Category::query()->create([
            'name' => 'Categoria de teste',
            'slug' => 'categoria-de-teste-'.$sequence,
            'description' => null,
            'is_active' => true,
        ]);
    }

    private function productData(array $overrides = []): array
    {
        return array_merge([
            'sku' => 'ADMIN-TEST-1',
            'name' => 'Produto administrativo',
            'slug' => 'produto-administrativo',
            'description' => 'Descrição do produto.',
            'price' => '10.00',
            'stock_quantity' => 0,
            'coloration' => 'colorido',
            'status' => 'active',
        ], $overrides);
    }

    private function createProduct(array $overrides = []): Product
    {
        static $sequence = 0;
        $sequence++;

        return Product::query()->create(array_merge([
            'sku' => 'ADMIN-PRODUCT-'.$sequence,
            'name' => 'Produto de administração',
            'slug' => 'produto-administracao-'.$sequence,
            'description' => 'Produto usado em teste isolado.',
            'price' => '10.00',
            'stock_quantity' => 5,
            'coloration' => 'colorido',
            'status' => 'active',
        ], $overrides));
    }
}
