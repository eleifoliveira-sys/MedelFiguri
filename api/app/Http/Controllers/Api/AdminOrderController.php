<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminOrderStatusRequest;
use App\Http\Resources\AdminOrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;

class AdminOrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function index(): JsonResponse
    {
        $orders = Order::query()
            ->with(['items', 'address', 'user', 'inventoryMovements.product'])
            ->latest('id')
            ->paginate(20);

        return AdminOrderResource::collection($orders)->response();
    }

    public function show(int $id): AdminOrderResource
    {
        return AdminOrderResource::make($this->loadOrder($id));
    }

    public function updateStatus(AdminOrderStatusRequest $request, int $id): AdminOrderResource
    {
        $order = $this->orders->transitionForAdmin($id, $request->validated('status'), [
            'processing', 'shipped', 'delivered',
        ]);

        return AdminOrderResource::make($this->loadOrder($order->id));
    }

    private function loadOrder(int $id): Order
    {
        return Order::query()
            ->with(['items', 'address', 'user', 'inventoryMovements.product'])
            ->findOrFail($id);
    }
}
