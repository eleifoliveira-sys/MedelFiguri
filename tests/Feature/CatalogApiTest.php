<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_it_lists_active_products_with_their_categories(): void
    {
        $category = Category::create([
            'name' => 'Animes',
            'slug' => 'animes',
            'is_active' => true,
        ]);
        $product = Product::create([
            'sku' => 'AN-001',
            'name' => 'Figure de teste',
            'slug' => 'figure-de-teste',
            'description' => 'Produto de teste.',
            'price' => 50,
            'stock_quantity' => 3,
            'coloration' => 'colorido',
            'status' => 'active',
        ]);
        $product->categories()->attach($category);
        $this->attachModel($product, 'renders/figure.glb');

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'figure-de-teste')
            ->assertJsonPath('data.0.categories.0.slug', 'animes')
            ->assertJsonPath('data.0.images.0.path', 'renders/figure.glb');
    }

    public function test_it_serves_category_tree_and_hides_inactive_products(): void
    {
        $category = Category::create([
            'name' => 'Animes',
            'slug' => 'animes',
            'is_active' => true,
        ]);
        Category::create([
            'name' => 'Aventura',
            'slug' => 'anime-aventura',
            'parent_id' => $category->id,
            'is_active' => true,
        ]);
        Product::create([
            'sku' => 'AN-002',
            'name' => 'Produto inativo',
            'slug' => 'produto-inativo',
            'description' => 'Produto inativo de teste.',
            'price' => 50,
            'stock_quantity' => 3,
            'coloration' => 'sem-cor',
            'status' => 'inactive',
        ]);
        Product::create([
            'sku' => 'AN-003',
            'name' => 'Produto sem modelo',
            'slug' => 'produto-sem-modelo',
            'description' => 'Produto ativo, mas sem arquivo 3D.',
            'price' => 50,
            'stock_quantity' => 3,
            'coloration' => 'sem-cor',
            'status' => 'active',
        ]);

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonPath('0.slug', 'animes')
            ->assertJsonPath('0.children.0.slug', 'anime-aventura');

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_it_returns_product_image_paths_for_the_catalog(): void
    {
        $product = Product::create([
            'sku' => 'MODEL-001',
            'name' => 'Figure com modelo 3D',
            'slug' => 'figure-com-modelo-3d',
            'description' => 'Produto de teste.',
            'price' => 50,
            'stock_quantity' => 3,
            'coloration' => 'colorido',
            'status' => 'active',
        ]);
        ProductImage::create([
            'product_id' => $product->id,
            'path' => 'renders/figure.glb',
            'alt_text' => 'Modelo 3D de teste',
            'sort_order' => 0,
            'is_primary' => true,
        ]);
        Storage::disk('public')->put('renders/figure.glb', 'fake 3d model');

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.images.0.path', 'renders/figure.glb')
            ->assertJsonPath('data.0.images.0.alt_text', 'Modelo 3D de teste');
    }

    public function test_it_hides_models_whose_files_are_missing_and_returns_not_found_for_details(): void
    {
        $product = Product::create([
            'sku' => 'MODEL-002',
            'name' => 'Figure sem arquivo 3D',
            'slug' => 'figure-sem-arquivo-3d',
            'description' => 'Produto de teste.',
            'price' => 50,
            'stock_quantity' => 3,
            'coloration' => 'colorido',
            'status' => 'active',
        ]);
        ProductImage::create([
            'product_id' => $product->id,
            'path' => 'renders/missing.glb',
            'alt_text' => 'Modelo 3D ausente',
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/products/figure-sem-arquivo-3d')->assertNotFound();
    }

    private function attachModel(Product $product, string $path): void
    {
        ProductImage::create([
            'product_id' => $product->id,
            'path' => $path,
            'alt_text' => 'Modelo 3D de teste',
            'sort_order' => 0,
            'is_primary' => true,
        ]);
        Storage::disk('public')->put($path, 'fake 3d model');
    }
}
