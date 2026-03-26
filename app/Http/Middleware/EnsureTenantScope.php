<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserLevel;
use App\Models\MasterAdmin;
use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantScope
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->route('tenant');
        $user = $request->user('admin');
        $tenantId = $tenant instanceof Tenant
            ? (int) $tenant->id
            : (is_numeric($tenant) ? (int) $tenant : null);

        if ($user instanceof MasterAdmin) {
            return $next($request);
        }

        if ($user instanceof User && $user->level === UserLevel::MASTER) {
            return $next($request);
        }

        if ($user instanceof User && $tenantId !== null && (int) $user->tenant_id === $tenantId) {
            return $next($request);
        }

        abort(404);
    }
}
