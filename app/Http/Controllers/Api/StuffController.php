<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStuffRequest;
use App\Http\Requests\UpdateStuffRequest;
use App\Http\Resources\StuffResource;
use App\Services\StuffService;

class StuffController extends Controller
{
    public function __construct(
        private StuffService $StuffService
    ) {}

    public function index()
    {
        $Stuffs = $this->StuffService->listAll();

        return StuffResource::collection($Stuffs);
    }

    public function store(StoreStuffRequest $request)
    {
        $Stuff = $this->StuffService->create($request->validated());

        return new StuffResource($Stuff);
    }

    public function show(int $id)
    {
        $Stuff = $this->StuffService->findById($id);

        return new StuffResource($Stuff);
    }

    public function update(UpdateStuffRequest $request, int $id)
    {
        $Stuff = $this->StuffService->update($id, $request->validated());

        return new StuffResource($Stuff);
    }

    public function destroy(int $id)
    {
        $this->StuffService->delete($id);

        return response()->noContent();
    }
}