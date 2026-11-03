<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_render_assets_idempotently(): void
    {
        Storage::fake('public');
        foreach (['lufyHead.glb', 'dbz.glb', 'friren.glb', 'gatobotas.glb', 'mario.glb', 'zard.glb', 'mander.glb'] as $file) {
            Storage::disk('public')->put($file, 'demo model');
        }

        $seeder = new CatalogSeeder;
        $seeder->run();
        Product::query()->where('sku', 'RENDER-LUFYHEAD')->update(['stock_quantity' => 1]);
        $seeder->run();

        $this->assertSame(6, Category::query()->count());
        $this->assertSame(15, Product::query()->count());
        $this->assertSame(1, Product::query()->where('sku', 'RENDER-LUFYHEAD')->value('stock_quantity'));
        $this->assertSame(7, ProductImage::query()->count());
        $this->assertSame(
            7,
            ProductImage::query()
                ->where('is_primary', true)
                ->whereHas('product', fn ($query) => $query->where('slug', 'like', 'render-%'))
                ->count(),
        );
    }
}
