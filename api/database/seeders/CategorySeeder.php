<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categoryDefinitions = [
            ['name' => 'Animes', 'slug' => 'animes'],
            ['name' => 'Jogos', 'slug' => 'jogos'],
        ];

        foreach ($categoryDefinitions as $definition) {
            Category::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'name' => $definition['name'],
                    'description' => $definition['name'] === 'Animes'
                        ? 'Personagens e modelos de animes.'
                        : 'Personagens e modelos de jogos.',
                    'parent_id' => null,
                    'is_active' => true,
                ],
            );
        }

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
            ->update(['is_active' => false]);
    }
}
