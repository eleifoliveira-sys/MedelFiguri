<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

class DevelopmentAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('The development administrator seeder is only allowed in local or testing environments.');
        }

        $data = [
            'name' => trim((string) config('development_admin.name')),
            'username' => trim((string) config('development_admin.username')),
            'email' => strtolower(trim((string) config('development_admin.email'))),
            'password' => (string) config('development_admin.password'),
        ];

        $user = User::query()->where('email', $data['email'])->first();
        $usernameRule = Rule::unique('users', 'username');

        if ($user !== null) {
            $usernameRule->ignore($user->getKey());
        }

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:150'],
            'username' => ['required', 'alpha_dash', 'max:50', $usernameRule],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            throw new RuntimeException(
                'Development administrator configuration is invalid. Check the required fields and uniqueness.',
            );
        }

        DB::transaction(function () use ($data, $user): void {
            $admin = $user ?? new User;
            $admin->name = $data['name'];
            $admin->username = $data['username'];
            $admin->email = $data['email'];

            if (! $admin->exists) {
                $admin->password = $data['password'];
            }

            $admin->save();

            $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
            $admin->roles()->syncWithoutDetaching([$adminRole->id]);
        });
    }
}
