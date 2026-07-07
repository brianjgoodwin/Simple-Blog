<?php

namespace App\Models;

use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// user_id is deliberately NOT fillable: pages are only ever created via the
// $user->pages()->create(...) relationship, which sets the owner itself. Keeping
// user_id out of $fillable makes it impossible for request input to ever set a
// page's owner, even if a future controller mass-assigns validated input.
#[Fillable(['slug', 'body'])]
class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory;

    /**
     * The author who owns this page.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
