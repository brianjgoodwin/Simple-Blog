<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Invite;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Invite-gated registration (Phase 11).
 *
 * This deliberately re-opens an unauthenticated account-creation endpoint;
 * the single-use invite code is the lock on it. The routes are throttled —
 * that throttle, times the code's ~70 bits, is what makes guessing
 * infeasible. Error messages about codes are honest ("already used" vs
 * "not valid"): guessing can't reach a valid-but-used code, and honesty
 * helps a confused tester. The resulting account is identical to
 * author:create output — same validation rules, same seeded pages, no
 * roles or flags.
 */
class RegisteredUserController extends Controller
{
    /**
     * Show the registration form. Invites travel as links
     * (/register?code=...), so the code field pre-fills from the query
     * string but stays editable for anyone typing a code by hand.
     */
    public function create(Request $request): View
    {
        return view('auth.register', [
            'code' => $request->query('code', ''),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'username' => User::usernameRules(),
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($validated): User {
            $invite = Invite::where('code', Invite::normalize($validated['code']))
                ->lockForUpdate()
                ->first();

            if ($invite === null) {
                throw ValidationException::withMessages([
                    'code' => __('That invite code is not valid.'),
                ]);
            }

            if ($invite->isUsed()) {
                throw ValidationException::withMessages([
                    'code' => __('That invite code has already been used.'),
                ]);
            }

            $user = User::create([
                'name' => $validated['name'],
                'username' => $validated['username'],
                'email' => $validated['email'],
                'password' => $validated['password'], // hashed by the model's cast
            ]);

            $user->seedDefaultPages();

            // Guarded stamp: the WHERE used_at IS NULL makes consumption
            // atomic on any database. lockForUpdate above covers engines
            // with row locks; on SQLite it's a no-op, so if two submits
            // ever race past the isUsed() check, exactly one matches this
            // UPDATE — the loser's registration rolls back.
            $claimed = Invite::whereKey($invite->id)
                ->whereNull('used_at')
                ->update(['used_at' => now(), 'used_by_id' => $user->id]);

            if ($claimed === 0) {
                throw ValidationException::withMessages([
                    'code' => __('That invite code has already been used.'),
                ]);
            }

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);

        return redirect()->route('dashboard');
    }
}
