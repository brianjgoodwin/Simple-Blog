<?php

namespace App\Http\Controllers;

use App\Models\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Pages (About, Links) are a fixed, seeded set — only ever edited, never
 * created or deleted here. They're addressed by slug, resolved through the
 * current author so one user can never reach another's page.
 */
class PageController extends Controller
{
    /**
     * The pages a user is allowed to edit.
     *
     * @var array<int, string>
     */
    private const EDITABLE = ['about', 'links'];

    /**
     * Show the edit form for one of the author's pages.
     */
    public function edit(string $slug): View
    {
        $page = $this->resolvePage($slug);

        return view('pages.edit', ['page' => $page]);
    }

    /**
     * Update one of the author's pages.
     */
    public function update(Request $request, string $slug): RedirectResponse
    {
        $page = $this->resolvePage($slug);

        $this->authorize('update', $page); // belt-and-suspenders over the scoped lookup

        $validated = $request->validate([
            'body' => ['nullable', 'string'],
        ]);

        $page->update(['body' => $validated['body'] ?? '']);

        return redirect()
            ->route('pages.edit', $page->slug)
            ->with('status', ucfirst($page->slug).' page saved.');
    }

    /**
     * Find the current author's page by slug, scoped to them.
     *
     * Because we query through the authenticated user's own pages, there is no
     * way to address another user's row. An unknown/invalid slug is a 404.
     */
    private function resolvePage(string $slug): Page
    {
        if (! in_array($slug, self::EDITABLE, true)) {
            throw new NotFoundHttpException();
        }

        return Auth::user()->pages()->where('slug', $slug)->firstOrFail();
    }
}
