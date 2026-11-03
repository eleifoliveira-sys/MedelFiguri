<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\DevelopmentAdminSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class DevelopmentAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_admin_and_does_not_reset_an_existing_password(): void
    {
        $password = Str::random(40);

        config()->set('development_admin', [
            'name' => 'Development Admin',
            'username' => 'development-admin',
            'email' => 'dev-admin@example.test',
            'password' => $password,
        ]);

        (new RoleSeeder)->run();
        (new DevelopmentAdminSeeder)->run();

        $user = User::query()->where('email', 'dev-admin@example.test')->firstOrFail();

        $this->assertSame('Development Admin', $user->name);
        $this->assertSame('development-admin', $user->username);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertTrue($user->roles()->where('slug', 'admin')->exists());

        config()->set('development_admin.password', Str::random(40));
        (new DevelopmentAdminSeeder)->run();

        $user->refresh();

        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertTrue($user->roles()->where('slug', 'admin')->exists());
    }
}
