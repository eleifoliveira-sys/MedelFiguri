<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            [
                'slug' => 'client',
                'name' => 'Cliente',
                'description' => 'Cliente da loja.',
            ],
            [
                'slug' => 'admin',
                'name' => 'Administrador',
                'description' => 'Administrador da loja.',
            ],
        ] as $role) {
            Role::updateOrCreate(
                ['slug' => $role['slug']],
                ['name' => $role['name'], 'description' => $role['description']],
            );
        }
    }
}
