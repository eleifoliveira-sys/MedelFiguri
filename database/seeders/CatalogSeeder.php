<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $renders = [
            ['file' => 'lufyHead.glb', 'category' => 'anime-aventura'],
            ['file' => 'dbz.glb', 'category' => 'anime-aventura'],
            ['file' => 'friren.glb', 'category' => 'anime-fantasia'],
            ['file' => 'gatobotas.glb', 'category' => 'fantasia-aventura'],
            ['file' => 'mario.glb', 'category' => 'jogos-plataforma'],
            ['file' => 'zard.glb', 'category' => 'anime-jogos-rpg'],
            ['file' => 'mander.glb', 'category' => 'jogos-rpg'],
        ];

        foreach ($renders as $render) {
            if (! Storage::disk('public')->exists($render['file'])) {
                throw new RuntimeException('A render asset is missing from storage/app/public.');
            }
        }

        $categoryDefinitions = [
            ['name' => 'Animes', 'slug' => 'animes', 'parent' => null],
            ['name' => 'Jogos', 'slug' => 'jogos', 'parent' => null],
            ['name' => 'Aventura', 'slug' => 'anime-aventura', 'parent' => 'animes'],
            ['name' => 'Fantasia', 'slug' => 'anime-fantasia', 'parent' => 'animes'],
            ['name' => 'Plataforma', 'slug' => 'jogos-plataforma', 'parent' => 'jogos'],
            ['name' => 'RPG', 'slug' => 'jogos-rpg', 'parent' => 'jogos'],
        ];
        $categories = [];

        foreach ($categoryDefinitions as $definition) {
            $parentId = $definition['parent'] === null
                ? null
                : Category::query()->where('slug', $definition['parent'])->value('id');

            if ($definition['parent'] !== null && $parentId === null) {
                throw new RuntimeException('A parent category could not be resolved.');
            }

            $categories[$definition['slug']] = Category::query()->firstOrCreate(
                ['slug' => $definition['slug']],
                [
                    'name' => $definition['name'],
                    'description' => 'Categoria de demonstração.',
                    'parent_id' => $parentId,
                    'is_active' => true,
                ],
            );
        }

        DB::transaction(function () use ($renders, $categories): void {
            foreach ($renders as $index => $render) {
                $sku = 'RENDER-'.strtoupper(pathinfo($render['file'], PATHINFO_FILENAME));
                $slug = 'render-'.strtolower(pathinfo($render['file'], PATHINFO_FILENAME));
                $product = Product::query()->firstOrCreate(
                    ['sku' => $sku],
                    [
                        'name' => 'Modelo 3D de demonstração '.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                        'slug' => $slug,
                        'description' => 'Modelo 3D neutro para demonstração do catálogo.',
                        'price' => '49.95',
                        'stock_quantity' => 3,
                        'coloration' => 'sem-cor',
                        'status' => 'active',
                    ],
                );
                $product->categories()->syncWithoutDetaching([$categories[$render['category']]->id]);
                ProductImage::query()->firstOrCreate(
                    ['product_id' => $product->id, 'path' => $render['file']],
                    [
                        'alt_text' => 'Modelo 3D de demonstração',
                        'sort_order' => 0,
                        'is_primary' => true,
                    ],
                );
            }
        }, );
    }
}
