<?php

namespace App\Http\Controllers;

use App\Enums\BlogFont;
use App\Enums\Theme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The author's appearance settings (Phase 10): theme + font for their
 * public blog. Scoped entirely to the logged-in user — no request
 * parameter ever selects whose settings change.
 */
class AppearanceController extends Controller
{
    public function edit(Request $request): View
    {
        return view('appearance.edit', [
            'user' => $request->user(),
            'themes' => Theme::cases(),
            'fonts' => BlogFont::cases(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'theme' => ['required', Rule::enum(Theme::class)],
            'font' => ['required', Rule::enum(BlogFont::class)],
            'description' => ['nullable', 'string', 'max:200'],
        ]);

        // Explicit assignment, not update(): theme, font, and description are
        // deliberately outside User's fillable list.
        $user = $request->user();
        $user->theme = $validated['theme'];
        $user->font = $validated['font'];
        // Store a blank description as null so downstream ("has a tagline?")
        // checks are a simple null test — no empty-string special case.
        $description = trim((string) ($validated['description'] ?? ''));
        $user->description = $description === '' ? null : $description;
        $user->save();

        return redirect()
            ->route('appearance.edit')
            ->with('status', __('Appearance updated.'));
    }
}
