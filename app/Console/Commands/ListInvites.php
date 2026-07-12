<?php

namespace App\Console\Commands;

use App\Models\Invite;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('invite:list')]
#[Description('List all invite codes: unused ones first, then who used the rest')]
class ListInvites extends Command
{
    public function handle(): int
    {
        $invites = Invite::with('usedBy')
            ->orderByRaw('used_at is not null') // unused first
            ->orderBy('created_at')
            ->get();

        if ($invites->isEmpty()) {
            $this->info('No invites yet. Create one with invite:generate.');

            return self::SUCCESS;
        }

        $this->table(
            ['Code', 'Note', 'Status'],
            $invites->map(fn (Invite $invite) => [
                $invite->displayCode(),
                $invite->note ?? '',
                $invite->isUsed()
                    ? 'used by @'.($invite->usedBy->username ?? '(deleted account)').' on '.$invite->used_at->toDateString()
                    : 'unused',
            ]),
        );

        return self::SUCCESS;
    }
}
