<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('author:unsuspend {username : The author to reinstate}')]
#[Description('Reinstate a suspended author: blog and login work again, nothing else changes')]
class UnsuspendAuthor extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $author = User::where('username', $this->argument('username'))->first();

        if ($author === null) {
            $this->error("No author with username '{$this->argument('username')}'.");

            return self::FAILURE;
        }

        if (! $author->isSuspended()) {
            $this->info("Author '{$author->username}' is not suspended.");

            return self::SUCCESS;
        }

        $author->suspended_at = null;
        $author->save();

        $this->info("Author '{$author->username}' reinstated. Blog and login work again.");

        return self::SUCCESS;
    }
}
