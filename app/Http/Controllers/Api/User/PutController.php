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
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

class PutController extends Controller
{
    /**
     * Update a tenant admin user.
     */
    public function __invoke(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'username' => [
                'required',
                'string',
                'max:255',
                'alpha_dash:ascii',
                'not_in:master',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => 'sometimes|nullable|string|min:8|max:255',
            'tenant_id' => 'required|integer|exists:tenants,id',
            'level' => ['sometimes', 'string', new In([UserLevel::TENANT->value])],
        ]);

        $username = strtolower((string) $validated['username']);

        $user->name = $username;
        $user->email = strtolower((string) $validated['email']);
        $user->username = $username;
        $user->tenant_id = (int) $validated['tenant_id'];
        $user->level = UserLevel::TENANT;

        if (array_key_exists('password', $validated) && ! empty($validated['password'])) {
            $user->password = Hash::make((string) $validated['password'], [
                'rounds' => 12,
            ]);
        }

        $user->save();
        $user->load('tenant');

        return response()->json(new UserPayload($user));
    }
}
