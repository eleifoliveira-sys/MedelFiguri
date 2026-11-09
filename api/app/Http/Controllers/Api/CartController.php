<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddCartItemRequest;
use App\Http\Requests\UpdateCartItemRequest;
use App\Http\Resources\CartResource;
use App\Models\Cart;
use App\Models\Users\User;
use App\Services\CartService;
use App\Support\GuestCartCookie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private readonly CartService $cartService) {}

    public function show(Request $request): JsonResponse
    {
        $cart = $this->currentCart($request);

        return $this->response($cart, $request);
    }

    public function addItem(AddCartItemRequest $request): JsonResponse
    {
        $cart = $this->currentCart($request);
        $cart = $this->cartService->addItem(
            $cart,
            $request->integer('product_id'),
            $request->integer('quantity'),
        );

        return $this->response($cart, $request);
    }

    public function updateItem(UpdateCartItemRequest $request, int $id): JsonResponse
    {
        $cart = $this->currentCart($request);
        $cart = $this->cartService->updateItem($cart, $id, $request->integer('quantity'));

        return $this->response($cart, $request);
    }

    public function removeItem(Request $request, int $id): JsonResponse
    {
        $cart = $this->currentCart($request);
        $cart = $this->cartService->removeItem($cart, $id);

        return $this->response($cart, $request);
    }

    public function showGuest(Request $request): JsonResponse
    {
        return $this->response($this->guestCart($request), $request);
    }

    public function updateGuestItem(UpdateCartItemRequest $request, int $id): JsonResponse
    {
        $cart = $this->cartService->updateItem(
            $this->guestCart($request),
            $id,
            $request->integer('quantity'),
        );

        return $this->response($cart, $request);
    }

    public function removeGuestItem(Request $request, int $id): JsonResponse
    {
        $cart = $this->cartService->removeItem($this->guestCart($request), $id);

        return $this->response($cart, $request);
    }

    public function mergeGuest(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $guestToken = $request->cookie(GuestCartCookie::NAME);
        $result = $this->cartService->mergeGuestCart(
            $user,
            is_string($guestToken) ? $guestToken : null,
        );
        $response = response()->json(['data' => $result]);

        if ($result['status'] === 'merged') {
            $response->headers->setCookie(GuestCartCookie::forget($request));
        }

        return $response;
    }

    private function currentCart(Request $request): Cart
    {
        $guestToken = $request->cookie(GuestCartCookie::NAME);

        return $this->cartService->current(
            $request->user(),
            is_string($guestToken) ? $guestToken : null,
        );
    }

    private function guestCart(Request $request): Cart
    {
        $guestToken = $request->cookie(GuestCartCookie::NAME);
        $cart = $this->cartService->guestByToken(
            is_string($guestToken) ? $guestToken : null,
        );

        abort_if($cart === null, 404);

        return $cart;
    }

    private function response(Cart $cart, Request $request): JsonResponse
    {
        $response = CartResource::make($cart)->response()->setStatusCode(200);

        if ($cart->user_id === null && $cart->guest_token !== null) {
            $response->headers->setCookie(GuestCartCookie::issue($cart->guest_token, $request));
        }

        return $response;
    }
}
