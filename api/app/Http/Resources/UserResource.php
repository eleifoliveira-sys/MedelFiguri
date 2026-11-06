<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles
                ->map(fn ($role): array => [
                    'slug' => $role->slug,
                    'name' => $role->name,
                ])
                ->values()),
        ];
    }
}
