<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

/**
 * The whole app's multi-tenancy hinges on this: an author may only ever
 * act on a post they own. Every rule below reduces to $post->user_id === $user->id.
 *
 * viewAny/create take no Post, so any authenticated author passes — they act
 * within their own scope, enforced by the controller querying $user->posts().
 */
class PostPolicy
{
    /**
     * Can the user see a list of (their own) posts? Any author can.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Can the user view this specific post in the dashboard?
     */
    public function view(User $user, Post $post): bool
    {
        return $user->id === $post->user_id;
    }

    /**
     * Can the user create a post? Any author can (it becomes theirs).
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Can the user edit this post?
     */
    public function update(User $user, Post $post): bool
    {
        return $user->id === $post->user_id;
    }

    /**
     * Can the user delete this post?
     */
    public function delete(User $user, Post $post): bool
    {
        return $user->id === $post->user_id;
    }
}
