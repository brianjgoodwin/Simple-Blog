<?php

namespace App\Policies;

use App\Models\Page;
use App\Models\User;

/**
 * Pages (About, Links) are seeded per author and only ever edited, never
 * created or deleted from the dashboard — so the only ability we need is
 * update, and it reduces to the same ownership rule as posts.
 */
class PagePolicy
{
    /**
     * Can the user edit this page?
     */
    public function update(User $user, Page $page): bool
    {
        return $user->id === $page->user_id;
    }
}
