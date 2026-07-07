<?php

namespace App\Enums;

/**
 * A post's visibility lifecycle.
 *
 * v1 uses Draft and Published. Unlisted is reserved for a later phase
 * (reachable by direct link, not shown on the blog home) — modeling it
 * here now keeps that door open without a schema change.
 */
enum PostStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    // case Unlisted = 'unlisted'; // deferred — see PLAN.md
}
