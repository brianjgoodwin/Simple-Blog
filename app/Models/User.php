<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\BlogFont;
use App\Enums\Theme;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'username', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The pages every author starts with — also the only editable slugs
     * (PageController) and the page files in an export.
     *
     * @var array<int, string>
     */
    public const DEFAULT_PAGES = ['about', 'links'];

    /**
     * Mirrors the database defaults so a User that hasn't been refreshed
     * from the DB still has usable appearance settings. Like suspended_at,
     * theme and font are deliberately NOT fillable — AppearanceController
     * assigns them explicitly after validating against the enums.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'theme' => 'default',
        'font' => 'sans',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'suspended_at' => 'datetime',
            'theme' => Theme::class,
            'font' => BlogFont::class,
        ];
    }

    /**
     * Has this author been suspended by the operator?
     *
     * A suspended author's blog 404s everywhere public and they cannot log
     * in — publicly indistinguishable from an account that never existed
     * (the same posture as drafts). Suspend/unsuspend via artisan.
     */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * The canonical username validation rules, shared by every path that
     * creates an account (author:create, invite registration). One copy,
     * so the rules can never fork and drift.
     *
     * @return array<int, string>
     */
    public static function usernameRules(): array
    {
        return ['required', 'string', 'lowercase', 'regex:/^[a-z0-9_]+$/', 'max:30', 'unique:users,username'];
    }

    /**
     * Seed the empty About and Links pages every new author starts with.
     * Call inside the same transaction that creates the user.
     */
    public function seedDefaultPages(): void
    {
        foreach (self::DEFAULT_PAGES as $slug) {
            $this->pages()->create([
                'slug' => $slug,
                'body' => '',
            ]);
        }
    }

    /**
     * Bind users in public routes by their (immutable) username, not id,
     * so /@{username} resolves the author.
     */
    public function getRouteKeyName(): string
    {
        return 'username';
    }

    /**
     * The pages (About, Links) belonging to this author.
     *
     * @return HasMany<Page, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    /**
     * The posts belonging to this author.
     *
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
