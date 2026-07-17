<?php

namespace App\Models;

use App\Enums\PostStatus;
use App\Support\Markdown;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\HtmlString;

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
     * The single render path.
     *
     * Whenever the Markdown `body` changes, re-render the cached `body_html`.
     * Every write flows through model saving — the controller's store/update,
     * the composer's background autosave, the factory, tinker — so the cache
     * cannot drift from its source as long as this event fires. When the
     * Markdown pipeline ITSELF changes (a heading-shift tweak, a CommonMark
     * upgrade), `php artisan posts:rerender` rebuilds every stored row. That
     * is the whole invalidation story: no TTLs, no cache keys.
     */
    protected static function booted(): void
    {
        static::saving(function (Post $post): void {
            if ($post->isDirty('body')) {
                $post->renderBodyHtml();
            }
        });
    }

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
     * Re-render the cached HTML from the canonical Markdown `body`.
     *
     * Called from the saving hook on every body change, and by
     * `posts:rerender` to rebuild stored rows after a pipeline change.
     */
    public function renderBodyHtml(): void
    {
        $this->body_html = (string) Markdown::toHtml($this->body);
    }

    /**
     * A short plain-text summary for meta descriptions and link previews.
     *
     * Drawn from the cached render (tags stripped, whitespace collapsed) so it
     * reflects exactly what readers see, not raw Markdown syntax.
     */
    public function excerpt(int $length = 160): string
    {
        return str(strip_tags((string) $this->body_html))->squish()->limit($length)->value();
    }

    /**
     * The cached, pre-rendered post HTML.
     *
     * Returned as an HtmlString so views echo it with `{{ }}` like any other
     * value: the content was already sanitized by App\Support\Markdown when it
     * was stored (raw HTML stripped, unsafe links neutralized), so it is safe
     * to emit unescaped — the safety lives in the render path, not the view.
     *
     * @return Attribute<HtmlString, never>
     */
    protected function bodyHtml(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => new HtmlString($value ?? ''),
        );
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
     * Scope: posts whose title or body contains the term (case-insensitive
     * substring match).
     *
     * Deliberately LIKE, not a full-text index: it behaves identically on
     * SQLite (dev) and MySQL (prod), needs no migration, and at single-blog
     * scale a scan is fine. If it ever outgrows that, swap this body for
     * driver-gated MySQL FULLTEXT without touching callers. Compose with
     * published() so only public posts are ever searched.
     *
     * @param  Builder<Post>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        // Escape the LIKE wildcards so a literal % or _ in the query matches
        // literally. '=' is the escape char — neither engine treats it
        // specially inside a string literal (unlike '\'), so `escape '='`
        // is portable across SQLite and MySQL.
        $term = str_replace(['=', '%', '_'], ['==', '=%', '=_'], $term);
        $pattern = '%'.$term.'%';

        $query->where(function (Builder $q) use ($pattern) {
            $q->whereRaw("title like ? escape '='", [$pattern])
                ->orWhereRaw("body like ? escape '='", [$pattern]);
        });
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
