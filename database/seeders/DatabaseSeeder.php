<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $requiredAdminVariables = [
            'development_admin.name',
            'development_admin.username',
            'development_admin.email',
            'development_admin.password',
        ];

        $missingVariables = array_filter(
            $requiredAdminVariables,
            fn (string $key): bool => blank(config($key)),
        );

        if ($missingVariables !== []) {
            throw new RuntimeException(
                'Configure the development administrator variables in .env before seeding.',
            );
        }

        $this->call([
            RoleSeeder::class,
            CatalogSeeder::class,
            DevelopmentAdminSeeder::class,
        ]);
    }
}
