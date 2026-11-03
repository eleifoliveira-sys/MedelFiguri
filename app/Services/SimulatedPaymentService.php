<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SimulatedPaymentService
{
    /** @return array{order: Order, already_confirmed: bool} */
    public function confirm(User $user, int $orderId): array
    {
        return DB::transaction(function () use ($user, $orderId): array {
            $order = Order::query()
                ->whereKey($orderId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status === 'paid') {
                return [
                    'order' => $order->load(['items', 'address']),
                    'already_confirmed' => true,
                ];
            }

            if ($order->status !== 'pending') {
                throw ValidationException::withMessages([
                    'order' => ['Este pedido não está aguardando confirmação de pagamento.'],
                ]);
            }

            $order->forceFill([
                'status' => 'paid',
                'payment_method' => 'simulated',
                'paid_at' => now(),
            ])->save();

            return [
                'order' => $order->load(['items', 'address']),
                'already_confirmed' => false,
            ];
        }, 3);
    }
}
