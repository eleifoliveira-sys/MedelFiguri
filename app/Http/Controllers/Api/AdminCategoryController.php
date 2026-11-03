<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdminCategoryRequest;
use App\Http\Requests\UpdateAdminCategoryRequest;
use App\Http\Resources\AdminCategoryResource;
use App\Services\AdminCategoryService;
use Illuminate\Http\JsonResponse;

class AdminCategoryController extends Controller
{
    public function __construct(private readonly AdminCategoryService $categories) {}

    public function index(): JsonResponse
    {
        return AdminCategoryResource::collection($this->categories->list())->response();
    }

    public function show(int $id): AdminCategoryResource
    {
        return AdminCategoryResource::make($this->categories->find($id));
    }

    public function store(StoreAdminCategoryRequest $request): JsonResponse
    {
        return AdminCategoryResource::make($this->categories->create($request->validated()))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateAdminCategoryRequest $request, int $id): AdminCategoryResource
    {
        return AdminCategoryResource::make($this->categories->update($id, $request->validated()));
    }

    public function destroy(int $id): AdminCategoryResource
    {
        return AdminCategoryResource::make($this->categories->archive($id));
    }
}
