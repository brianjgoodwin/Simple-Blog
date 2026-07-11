<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('author:suspend {username : The author to suspend}')]
#[Description('Suspend an author: their blog 404s everywhere public and they cannot log in')]
class SuspendAuthor extends Command
{
    /**
     * Execute the console command.
     *
     * This exists so a content problem at 11pm is handled with one command
     * instead of hand-editing production SQLite. The author's words are NOT
     * touched — unsuspend (or an export) restores everything.
     */
    public function handle(): int
    {
        $author = User::where('username', $this->argument('username'))->first();

        if ($author === null) {
            $this->error("No author with username '{$this->argument('username')}'.");

            return self::FAILURE;
        }

        if ($author->isSuspended()) {
            $this->info("Author '{$author->username}' is already suspended (since {$author->suspended_at->toDateTimeString()}).");

            return self::SUCCESS;
        }

        $author->suspended_at = now();
        $author->save();

        $this->info("Author '{$author->username}' suspended. Their blog now 404s and they cannot log in.");
        $this->line('Already-issued sessions are rejected on their next request.');

        return self::SUCCESS;
    }
}
