<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\UserLevel;
use App\Http\Controllers\Controller;
use App\Models\MasterAdmin;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tymon\JWTAuth\JWTGuard;

class TokenController extends Controller
{
    /**
     * Generate a JWT token for the master admin.
     */
    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => 'required|string|max:255',
            'password' => 'required|string',
        ]);

        $credentials = [
            'username' => (string) $validated['username'],
            'password' => (string) $validated['password'],
        ];

        $token = auth('admin')
            ->attempt($credentials);

        if (! $token || ! is_string($token)) {
            return response()->json([
                'error' => 'Invalid credentials',
            ], 401);
        }

        try {
            /** @var MasterAdmin|User|null $user */
            $user = auth('admin')
                ->user();
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Could not create token',
            ], 500);
        }

        if (! $user) {
            return response()->json([
                'error' => 'Could not resolve authenticated user',
            ], 500);
        }

        return $this->respondWithTokenAndRefreshCookie($token, [
            'user' => $this->buildUserPayload($user),
        ]);
    }

    /**
     * Refresh the JWT token using the refresh token from the HTTP-only cookie.
     */
    public function refresh(Request $request): JsonResponse
    {
        $refreshToken = $request->cookie('refresh_token');

        if (! $refreshToken) {
            return response()->json([
                'error' => 'Could not refresh token',
            ], 401);
        }

        try {
            /** @var JWTGuard $guard */
            $guard = auth('admin');
            $token = $guard->setToken($refreshToken)
                ->refresh();

            return $this->respondWithTokenAndRefreshCookie($token);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Could not refresh token',
            ], 401)->withCookie($this->forgetRefreshCookie());
        }
    }

    /**
     * Clear the refresh token cookie on logout.
     */
    public function logout(): JsonResponse
    {
        return response()->json([
            'message' => 'Logged out',
        ])->withCookie($this->forgetRefreshCookie());
    }

    /**
     * Get the authenticated master admin.
     */
    public function me(): JsonResponse
    {
        try {
            /** @var MasterAdmin|User|null $user */
            $user = auth('admin')
                ->user();

            if (! $user) {
                return response()->json([
                    'error' => 'User not found',
                ], 404);
            }

            return response()->json($this->buildUserPayload($user));
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Unauthorized',
            ], 401);
        }
    }

    /**
     * Build a JSON response with the access token and set the refresh token as an HTTP-only cookie.
     *
     * @param  array<string, mixed>  $extra
     */
    private function respondWithTokenAndRefreshCookie(string $token, array $extra = []): JsonResponse
    {
        $refreshTtlMinutes = (int) config('jwt.refresh_ttl', 20160);

        $cookie = cookie(
            'refresh_token',
            $token,
            $refreshTtlMinutes,
            '/api/admin/token',
            null,
            true,
            true,
            false,
            'Strict',
        );

        return response()->json(array_merge([
            'token' => $token,
            'token_type' => 'bearer',
            'expires_in' => config('jwt.ttl') * 60,
        ], $extra))->withCookie($cookie);
    }

    /**
     * Create a cookie that forgets/clears the refresh token.
     */
    private function forgetRefreshCookie(): \Symfony\Component\HttpFoundation\Cookie
    {
        return cookie()->forget('refresh_token', '/api/admin/token');
    }

    /**
     * Build response payload for current authenticated admin.
     *
     * @return array<string, mixed>
     */
    private function buildUserPayload(MasterAdmin|User $user): array
    {
        if ($user instanceof MasterAdmin) {
            return [
                'is_master' => true,
                'level' => UserLevel::MASTER->value,
                'identifier' => $user->getJWTIdentifier(),
                'username' => 'master',
                'tenant_id' => null,
            ];
        }

        return [
            'is_master' => $user->isMaster(),
            'level' => $user->level->value,
            'identifier' => $user->getJWTIdentifier(),
            'username' => $user->username,
            'tenant_id' => $user->tenant_id,
        ];
    }
}
