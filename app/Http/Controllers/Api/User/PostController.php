<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\User;

use App\Enums\UserLevel;
use App\Http\Controllers\Controller;
use App\Http\Payloads\UserPayload;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\In;

class PostController extends Controller
{
    /**
     * Create a new tenant admin user.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => 'required|string|max:255|alpha_dash:ascii|not_in:master|unique:users,username',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|max:255',
            'tenant_id' => 'required|integer|exists:tenants,id',
            'level' => ['sometimes', 'string', new In([UserLevel::TENANT->value])],
        ]);

        $username = strtolower((string) $validated['username']);

        $user = User::create([
            'name' => $username,
            'email' => strtolower((string) $validated['email']),
            'username' => $username,
            'tenant_id' => $validated['tenant_id'],
            'level' => UserLevel::TENANT->value,
            'password' => Hash::make((string) $validated['password'], [
                'rounds' => 12,
            ]),
            'email_verified_at' => now(),
        ]);

        $user->load('tenant');

        return response()->json(new UserPayload($user), 201);
    }
}
