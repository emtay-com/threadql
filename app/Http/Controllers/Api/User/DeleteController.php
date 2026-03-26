<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Response;

class DeleteController extends Controller
{
    /**
     * Delete an admin user.
     */
    public function __invoke(User $user): Response
    {
        $user->delete();

        return response()->noContent();
    }
}
