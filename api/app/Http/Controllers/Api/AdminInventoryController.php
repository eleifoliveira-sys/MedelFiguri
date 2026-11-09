<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustInventoryRequest;
use App\Http\Resources\AdminInventoryMovementResource;
use App\Http\Resources\AdminProductResource;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Users\User;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;

class AdminInventoryController extends Controller
{
    public function index(): JsonResponse
    {
        $movements = InventoryMovement::query()
            ->with('product:id,name')
            ->latest('id')
            ->paginate(30);

        return AdminInventoryMovementResource::collection($movements)->response();
    }

    public function adjust(AdjustInventoryRequest $request, int $productId, InventoryService $inventory): AdminProductResource
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $product = Product::query()->findOrFail($productId);
        $data = $request->validated();

        return AdminProductResource::make($inventory->adjustStock(
            $product,
            (int) $data['quantity_delta'],
            $data['reason'],
            $user,
        )->load(['categories', 'images']));
    }
}
