<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StuffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'title'      => $this->title,
            'author'     => $this->author,
            'color'      => $this->bool,
            'inStuff'      => $this->whenLoaded('inStuff', fn ($inStuff) => [
                'id'   => $inStuff->id,
                'name' => $inStuff->name,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}