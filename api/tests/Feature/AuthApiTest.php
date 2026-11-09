<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Cliente', 'slug' => 'client']);
        Role::create(['name' => 'Administrador', 'slug' => 'admin']);
        config()->set('app.terms_version', 'terms-test-v1');
        config()->set('sanctum.stateful', ['localhost:8080', '127.0.0.1:8080']);
        $this->withHeader('Origin', 'http://localhost:8080');
    }

    public function test_registration_creates_a_client_with_hashed_password_and_terms(): void
    {
        $response = $this->postJson('/api/register', $this->registrationData());

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Test Client')
            ->assertJsonPath('data.username', 'test-client')
            ->assertJsonPath('data.email', 'client@example.test')
            ->assertJsonPath('data.roles.0.slug', 'client')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');

        $user = User::query()->where('email', 'client@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('SecurePass123!', $user->password));
        $this->assertNotSame('SecurePass123!', $user->password);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertSame('terms-test-v1', $user->terms_version);
        $this->assertFalse($user->roles()->where('slug', 'admin')->exists());
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'client@example.test']);
        $data = $this->registrationData();
        $data['email'] = 'CLIENT@EXAMPLE.TEST';

        $this->postJson('/api/register', $data)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_registration_rejects_duplicate_username(): void
    {
        User::factory()->create(['username' => 'test-client']);

        $this->postJson('/api/register', $this->registrationData())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');
    }

    public function test_registration_requires_terms_acceptance(): void
    {
        $data = $this->registrationData();
        unset($data['terms_accepted']);

        $this->postJson('/api/register', $data)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('terms_accepted');
    }

    public function test_registration_does_not_allow_admin_role_assignment(): void
    {
        $this->postJson('/api/register', [
            ...$this->registrationData(),
            'role' => 'admin',
        ])->assertCreated()
            ->assertJsonPath('data.roles.0.slug', 'client');

        $user = User::query()->where('email', 'client@example.test')->firstOrFail();
        $this->assertSame(['client'], $user->roles()->pluck('slug')->all());
    }

    public function test_login_accepts_email(): void
    {
        $user = $this->createUser('login@example.test', 'login-user');

        $this->postJson('/api/login', [
            'login' => 'login@example.test',
            'password' => 'SecurePass123!',
        ])->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.roles.0.slug', 'client')
            ->assertJsonMissingPath('data.password');
    }

    public function test_login_accepts_username(): void
    {
        $user = $this->createUser('login@example.test', 'login-user');

        $this->postJson('/api/login', [
            'login' => 'login-user',
            'password' => 'SecurePass123!',
        ])->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_login_rejects_invalid_credentials_without_identifying_the_account(): void
    {
        $this->createUser('login@example.test', 'login-user');

        $this->postJson('/api/login', [
            'login' => 'login@example.test',
            'password' => 'IncorrectPass123!',
        ])->assertUnauthorized()
            ->assertExactJson(['message' => 'As credenciais informadas são inválidas.']);
    }

    public function test_authenticated_user_can_read_their_profile(): void
    {
        $user = $this->createUser('me@example.test', 'me-user');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'me@example.test')
            ->assertJsonPath('data.roles.0.slug', 'client')
            ->assertJsonMissingPath('data.password');
    }

    public function test_guest_cannot_read_their_profile(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_logout_invalidates_the_authenticated_session(): void
    {
        $user = $this->createUser('logout@example.test', 'logout-user');

        $this->postJson('/api/login', [
            'login' => $user->email,
            'password' => 'SecurePass123!',
        ])->assertOk();
        $this->getJson('/api/me')->assertOk();
        $this->postJson('/api/logout')->assertOk();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_admin_can_access_admin_endpoint(): void
    {
        $user = $this->createUser('admin@example.test', 'admin-user');
        $user->roles()->sync([Role::query()->where('slug', 'admin')->value('id')]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.roles.0.slug', 'admin');
    }

    public function test_client_is_forbidden_from_admin_endpoint(): void
    {
        $user = $this->createUser('client@example.test', 'client-user');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/me')
            ->assertForbidden();
    }

    public function test_guest_is_unauthenticated_from_admin_endpoint(): void
    {
        $this->getJson('/api/admin/me')->assertUnauthorized();
    }

    /** @return array<string, mixed> */
    private function registrationData(): array
    {
        return [
            'name' => 'Test Client',
            'username' => 'test-client',
            'email' => 'client@example.test',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'terms_accepted' => true,
        ];
    }

    private function createUser(string $email, string $username): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'username' => $username,
            'password' => Hash::make('SecurePass123!'),
        ]);
        $user->roles()->attach(Role::query()->where('slug', 'client')->value('id'));

        return $user;
    }
}
