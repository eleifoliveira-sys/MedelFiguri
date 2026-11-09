<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Users\User;
use App\Support\GuestCartCookie;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuthService
{
    public function __construct(private readonly CartService $cartService) {}

    public function register(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => strtolower($data['email']),
                'password' => $data['password'],
                'terms_accepted_at' => now(),
                'terms_version' => config('app.terms_version'),
            ]);

            $clientRole = Role::query()->where('slug', 'client')->firstOrFail();
            $user->roles()->attach($clientRole);

            return $user->load('roles:id,name,slug');
        });
    }

    /**
     * @return array{user: User, cart_merge: array<string, mixed>}|null
     */
    public function login(array $credentials, Request $request): ?array
    {
        $identifier = trim($credentials['login']);
        $query = User::query();

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $query->where('email', strtolower($identifier));
        } else {
            $query->where('username', $identifier);
        }

        $user = $query->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            return null;
        }

        $this->webGuard()->login($user);
        $request->session()->regenerate();

        try {
            $cartMerge = $this->cartService->mergeGuestCart(
                $user,
                $request->cookie(GuestCartCookie::NAME),
            );
        } catch (Throwable $exception) {
            Log::error('Cart merge failed after successful login.', [
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);

            $cartMerge = [
                'status' => 'needs_attention',
                'reason' => 'merge_failed',
            ];
        }

        return [
            'user' => $user->load('roles:id,name,slug'),
            'cart_merge' => $cartMerge,
        ];
    }

    public function logout(Request $request): void
    {
        $this->webGuard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::forgetGuards();
    }

    private function webGuard(): StatefulGuard
    {
        return Auth::guard('web');
    }
}
