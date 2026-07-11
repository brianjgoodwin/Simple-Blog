<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of an author who was suspended AFTER logging in.
 *
 * Blocking at the login form (LoginRequest) is not enough: with the file
 * session driver there is no practical way to find and destroy a specific
 * user's existing sessions at suspend time, so instead every authenticated
 * dashboard request re-checks. Suspension therefore takes effect on the
 * author's next request, not instantly — acceptable for a hand-operated
 * suspend command.
 */
class EnsureAuthorNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && Auth::user()->isSuspended()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // No message: the login form will tell them nothing either.
            return redirect()->route('login');
        }

        return $next($request);
    }
}
