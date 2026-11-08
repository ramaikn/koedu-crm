<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Membatasi route per role. Pemakaian: ->middleware('role:admin,director').
 * Aturan detail per data (mis. tutor hanya melihat sesinya) tetap di Policy.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $allowed = array_map(fn (string $role) => UserRole::from($role), $roles);

        abort_unless($user !== null && $user->is_active && $user->hasRole(...$allowed), 403);

        return $next($request);
    }
}
