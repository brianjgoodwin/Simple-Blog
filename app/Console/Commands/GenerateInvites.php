<?php

namespace App\Console\Commands;

use App\Models\Invite;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('invite:generate
    {count=1 : How many codes to create}
    {--note= : A memory aid, e.g. "for Dave" or a batch label}')]
#[Description('Generate single-use invite codes and print them with their registration links')]
class GenerateInvites extends Command
{
    public function handle(): int
    {
        $count = (int) $this->argument('count');

        if ($count < 1 || $count > 100) {
            $this->error('Count must be between 1 and 100.');

            return self::FAILURE;
        }

        for ($i = 0; $i < $count; $i++) {
            $invite = Invite::create([
                'code' => Invite::generateCode(),
                'note' => $this->option('note'),
            ]);

            // The URL is how codes are meant to travel: it pre-fills the
            // (editable) code field on the register form.
            $this->line($invite->displayCode().'  '.route('register', ['code' => $invite->displayCode()]));
        }

        $this->info($count === 1 ? '1 invite created.' : "{$count} invites created.");

        return self::SUCCESS;
    }
}
