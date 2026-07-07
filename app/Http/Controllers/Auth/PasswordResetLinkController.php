<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Attempt to send the reset link. We deliberately IGNORE the returned
        // status and always show the same "link sent" message: reporting
        // "we can't find a user with that email" would let an attacker enumerate
        // which addresses have accounts. A real user's email still goes out; a
        // non-existent address simply produces no email but the same response.
        Password::sendResetLink(
            $request->only('email')
        );

        return back()->with('status', __(Password::RESET_LINK_SENT));
    }
}
