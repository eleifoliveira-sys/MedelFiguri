<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\Auth\AuthenticatedUserResource;
use App\Services\AuthService;
use App\Support\GuestCartCookie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->authService->register($request->validated());

        return AuthenticatedUserResource::make($user)
            ->additional(['message' => 'Cadastro realizado. Faça login para continuar.'])
            ->response()
            ->setStatusCode(201);
    }

    public function login(LoginRequest $request): JsonResponse|AuthenticatedUserResource
    {
        $result = $this->authService->login($request->validated(), $request);

        if ($result === null) {
            return response()->json(['message' => 'As credenciais informadas são inválidas.'], 401);
        }

        $response = AuthenticatedUserResource::make($result['user'])
            ->additional(['cart_merge' => $result['cart_merge']])
            ->response();

        if ($result['cart_merge']['status'] === 'merged') {
            $response->headers->setCookie(GuestCartCookie::forget($request));
        }

        return $response;
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request);

        return response()->json(['message' => 'Sessão encerrada.']);
    }
}
