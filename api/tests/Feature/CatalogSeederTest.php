<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Database\Seeders\CategorySeeder;
use Database\Seeders\RenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_archives_obsolete_seeded_categories(): void
    {
        foreach ([
            'anime',
            'fantasia',
            'anime-aventura',
            'anime-fantasia',
            'fantasia-aventura',
            'anime-jogos-rpg',
            'jogos-plataforma',
            'jogos-rpg',
        ] as $slug) {
            Category::query()->create([
                'name' => $slug,
                'slug' => $slug,
                'is_active' => true,
            ]);
        }

        (new CategorySeeder)->run();

        $this->assertSame(
            0,
            Category::query()
                ->whereIn('slug', [
                    'anime',
                    'fantasia',
                    'anime-aventura',
                    'anime-fantasia',
                    'fantasia-aventura',
                    'anime-jogos-rpg',
                    'jogos-plataforma',
                    'jogos-rpg',
                ])
                ->where('is_active', true)
                ->count(),
        );
        $this->assertSame(
            ['animes', 'jogos'],
            Category::query()
                ->where('is_active', true)
                ->orderBy('slug')
                ->pluck('slug')
                ->all(),
        );
    }

    public function test_it_seeds_render_assets_idempotently(): void
    {
        Storage::fake('public');
        foreach (['lufyHead.glb', 'dbz.glb', 'friren.glb', 'gatobotas.glb', 'mario.glb', 'zard.glb', 'mander.glb'] as $file) {
            Storage::disk('public')->put($file, 'demo model');
        }

        $categorySeeder = new CategorySeeder;
        $renderSeeder = new RenderSeeder;
        $categorySeeder->run();
        $renderSeeder->run();
        Product::query()->where('sku', 'RENDER-LUFYHEAD')->update(['stock_quantity' => 1]);
        Product::query()->where('sku', 'RENDER-DBZ')->update(['description' => 'Descrição anterior.']);
        $categorySeeder->run();
        $renderSeeder->run();

        $this->assertSame(2, Category::query()->count());
        $this->assertSame(
            ['animes', 'jogos'],
            Category::query()->orderBy('slug')->pluck('slug')->all(),
        );
        $this->assertSame(7, Product::query()->count());
        $this->assertSame(1, Product::query()->where('sku', 'RENDER-LUFYHEAD')->value('stock_quantity'));
        $this->assertSame(
            'Personagem do anime Dragon Ball Z.',
            Product::query()->where('sku', 'RENDER-DBZ')->value('description'),
        );
        $this->assertSame(
            'Personagem Mario, da série de jogos Super Mario.',
            Product::query()->where('sku', 'RENDER-MARIO')->value('description'),
        );
        $this->assertSame(7, ProductImage::query()->count());
        $this->assertSame(
            7,
            ProductImage::query()
                ->where('is_primary', true)
                ->whereHas('product', fn ($query) => $query->where('slug', 'like', 'render-%'))
                ->count(),
        );
        $this->assertSame(
            array_fill(0, 7, 1),
            Product::query()
                ->where('slug', 'like', 'render-%')
                ->withCount('categories')
                ->orderBy('id')
                ->pluck('categories_count')
                ->all(),
        );
    }
}
