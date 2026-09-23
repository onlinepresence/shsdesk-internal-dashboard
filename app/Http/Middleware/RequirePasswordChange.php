<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordChange
{
    /**
     * Flagged users may only reach their profile (where the password
     * form lives) and logout. Livewire updates ride along because the
     * password form itself is a Livewire component; every ops action
     * still enforces its own ability gate server-side.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && (bool) $user->must_change_password
            && ! $request->is('livewire/*')
            && ! $request->routeIs('profile', 'logout', 'verification.*')
        ) {
            return redirect()->route('profile');
        }

        return $next($request);
    }
}
