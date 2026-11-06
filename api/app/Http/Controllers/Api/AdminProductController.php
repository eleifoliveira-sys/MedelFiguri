<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdminProductRequest;
use App\Http\Requests\StoreProductImageRequest;
use App\Http\Requests\UpdateAdminProductRequest;
use App\Http\Resources\AdminProductResource;
use App\Models\User;
use App\Services\AdminProductService;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;

class AdminProductController extends Controller
{
    public function __construct(private readonly AdminProductService $products) {}

    public function index(): JsonResponse
    {
        return AdminProductResource::collection($this->products->list())->response();
    }

    public function show(int $id): AdminProductResource
    {
        return AdminProductResource::make($this->products->find($id));
    }

    public function store(StoreAdminProductRequest $request, InventoryService $inventory): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $product = $this->products->create($request->validated(), $user, $inventory);

        return AdminProductResource::make($product)->response()->setStatusCode(201);
    }

    public function update(UpdateAdminProductRequest $request, int $id): AdminProductResource
    {
        return AdminProductResource::make($this->products->update($id, $request->validated()));
    }

    public function destroy(int $id): AdminProductResource
    {
        return AdminProductResource::make($this->products->archive($id));
    }

    public function storeImage(StoreProductImageRequest $request, int $productId): JsonResponse
    {
        $image = $this->products->storeImage(
            $productId,
            $request->file('image'),
            $request->validated('alt_text'),
            $request->boolean('is_primary'),
        );

        return response()->json([
            'data' => [
                'id' => $image->id,
                'path' => $image->path,
                'alt_text' => $image->alt_text,
                'is_primary' => $image->is_primary,
                'url' => '/api/storage/'.$image->path,
            ],
        ], 201);
    }

    public function destroyImage(int $productId, int $imageId): JsonResponse
    {
        $this->products->deleteImage($productId, $imageId);

        return response()->json(['message' => 'Imagem removida.']);
    }
}
