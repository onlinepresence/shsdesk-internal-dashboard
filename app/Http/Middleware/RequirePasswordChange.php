<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordChange
{
    /**
     * Livewire components a flagged user may still talk to: the profile
     * forms on the allowed profile page, the navigation shell carrying
     * logout, and the verification banner. Everything else — including
     * ops actions replayed past the route middleware — bounces.
     *
     * @var list<string>
     */
    protected const LIVEWIRE_ALLOWLIST = [
        'profile.update-password-form',
        'profile.update-profile-information-form',
        'profile.delete-user-form',
        'layout.navigation',
        'layout.verify-banner',
    ];

    /**
     * Flagged users may only reach their profile (where the password
     * form lives), verification, and logout. Nothing else — every other
     * page and every non-allowlisted Livewire call redirects to profile
     * until THEY rotate the credential.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! (bool) $user->must_change_password) {
            return $next($request);
        }

        if ($request->is('livewire/*') && $this->isAllowlistedLivewireCall($request)) {
            return $next($request);
        }

        if (! $request->is('livewire/*') && $request->routeIs('profile', 'logout', 'verification.*')) {
            return $next($request);
        }

        return redirect()->route('profile');
    }

    /**
     * Whether every component targeted by a Livewire update is one a
     * flagged user is allowed to use. Unknown shapes fail closed.
     */
    protected function isAllowlistedLivewireCall(Request $request): bool
    {
        $components = $request->input('components');

        if (! is_array($components) || $components === []) {
            return false;
        }

        foreach ($components as $component) {
            $name = is_array($component) ? ($component['snapshot']['memo']['name'] ?? null) : null;

            if (! in_array($name, self::LIVEWIRE_ALLOWLIST, true)) {
                return false;
            }
        }

        return true;
    }
}
