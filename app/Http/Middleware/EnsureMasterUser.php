<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserLevel;
use App\Models\MasterAdmin;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMasterUser
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('admin');

        if ($user instanceof MasterAdmin) {
            return $next($request);
        }

        if ($user instanceof User && $user->level === UserLevel::MASTER) {
            return $next($request);
        }

        abort(403);
    }
}
