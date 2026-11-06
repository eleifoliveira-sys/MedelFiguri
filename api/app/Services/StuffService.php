<?php

namespace App\Services;

use App\Models\Stuff;
use Illuminate\Database\Eloquent\Collection;

class StuffService
{
    public function listAll(): Collection
    {
        $Stuffs = Stuff::with('inStuff:id,name')->get();

        return $Stuffs;
    }

    public function create(array $data): Stuff
    {
        if (($data['status'] ?? 'to_read') !== 'finished') {
            $data['rating'] = null;
        }

        $Stuff = Stuff::create($data);

        return $Stuff;
    }

    public function findById(int $id): Stuff
    {
        $Stuff = Stuff::with('inStuff:id,name')->findOrFail($id);

        return $Stuff;
    }

    public function update(int $id, array $data): Stuff
    {
        $Stuff = Stuff::findOrFail($id);
        $Stuff->fill($data);

        if ($Stuff->status !== 'finished') {
            $Stuff->rating = null;
        }

        $Stuff->save();

        return $Stuff;
    }

    public function delete(int $id): void
    {
        $Stuff = Stuff::findOrFail($id);
        $Stuff->delete();
    }
}