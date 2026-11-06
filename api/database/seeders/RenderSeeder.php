<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RenderSeeder extends Seeder
{
    public function run(): void
    {
        $renders = [
            ['file' => 'lufyHead.glb', 'category' => 'animes', 'description' => 'Personagem Luffy, do anime One Piece.'],
            ['file' => 'dbz.glb', 'category' => 'animes', 'description' => 'Personagem do anime Dragon Ball Z.'],
            ['file' => 'friren.glb', 'category' => 'animes', 'description' => 'Personagem Frieren, do anime Frieren.'],
            ['file' => 'gatobotas.glb', 'category' => 'animes', 'description' => 'Personagem Gato de Botas, da animação de mesmo nome.'],
            ['file' => 'mario.glb', 'category' => 'jogos', 'description' => 'Personagem Mario, da série de jogos Super Mario.'],
            ['file' => 'zard.glb', 'category' => 'jogos', 'description' => 'Modelo de personagem da categoria de jogos.'],
            ['file' => 'mander.glb', 'category' => 'jogos', 'description' => 'Modelo de personagem da categoria de jogos.'],
        ];

        foreach ($renders as $render) {
            if (! Storage::disk('public')->exists($render['file'])) {
                throw new RuntimeException('A render asset is missing from storage/app/public.');
            }
        }

        $categories = Category::query()
            ->whereIn('slug', array_column($renders, 'category'))
            ->get()
            ->keyBy('slug');

        foreach (array_unique(array_column($renders, 'category')) as $categorySlug) {
            if (! $categories->has($categorySlug)) {
                throw new RuntimeException('A render category could not be resolved.');
            }
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
                        'description' => $render['description'],
                        'price' => '49.95',
                        'stock_quantity' => 3,
                        'coloration' => 'sem-cor',
                        'status' => 'active',
                    ],
                );
                $product->update(['description' => $render['description']]);
                $product->categories()->sync([$categories[$render['category']]->id]);
                ProductImage::query()->firstOrCreate(
                    ['product_id' => $product->id, 'path' => $render['file']],
                    [
                        'alt_text' => 'Modelo 3D de demonstração',
                        'sort_order' => 0,
                        'is_primary' => true,
                    ],
                );
            }
        }, 3);
    }
}
