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
