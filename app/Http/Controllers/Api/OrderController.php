<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmPaymentRequest;
use App\Http\Resources\OrderResource;
use App\Models\User;
use App\Services\OrderService;
use App\Services\SimulatedPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly SimulatedPaymentService $paymentService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return OrderResource::collection($this->orderService->listFor($user));
    }

    public function show(Request $request, int $id): OrderResource
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return OrderResource::make($this->orderService->findFor($user, $id));
    }

    public function confirmPayment(ConfirmPaymentRequest $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $result = $this->paymentService->confirm($user, $id);

        return OrderResource::make($result['order'])
            ->additional(['payment' => [
                'simulated' => true,
                'already_confirmed' => $result['already_confirmed'],
                'message' => 'Pagamento simulado — nenhuma cobrança real foi realizada.',
            ]])
            ->response();
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $result = $this->orderService->cancel($user, $id);

        return OrderResource::make($result['order'])
            ->additional([
                'cancellation' => [
                    'already_cancelled' => $result['already_cancelled'],
                    'inventory_restored' => $result['inventory_restored'],
                ],
            ])
            ->response();
    }
}
