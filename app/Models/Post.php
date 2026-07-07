<?php

namespace App\Models;

use App\Enums\PostStatus;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Note: user_id, slug, status, and published_at are deliberately NOT fillable.
 * They are set server-side (ownership, slug freezing, publish lifecycle) and
 * must never come straight from request input.
 */
#[Fillable(['title', 'body'])]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * The author who owns this post.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope: only published posts (what the public may see).
     *
     * @param  Builder<Post>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', PostStatus::Published);
    }

    /**
     * Scope: only drafts.
     *
     * @param  Builder<Post>  $query
     */
    public function scopeDraft(Builder $query): void
    {
        $query->where('status', PostStatus::Draft);
    }

    /**
     * Is this post publicly visible?
     */
    public function isPublished(): bool
    {
        return $this->status === PostStatus::Published;
    }

    /**
     * Publish this post.
     *
     * Sets published_at only on the FIRST publish, so re-publishing after an
     * unpublish keeps the original publication date. The slug is already
     * frozen by the controller (it stops regenerating once published), so
     * nothing here touches the slug.
     */
    public function publish(): void
    {
        $this->status = PostStatus::Published;
        $this->published_at ??= now();
        $this->save();
    }

    /**
     * Return this post to draft.
     *
     * Keeps the slug and published_at intact: if it's published again later,
     * the same URL and original date are reused.
     */
    public function unpublish(): void
    {
        $this->status = PostStatus::Draft;
        $this->save();
    }
}
