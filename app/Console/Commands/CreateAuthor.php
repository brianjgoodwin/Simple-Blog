<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('author:create
    {--name= : Display name (used for the byline)}
    {--username= : Immutable, appears in URLs (a-z, 0-9, _)}
    {--email= : Login email}
    {--password= : Password (prompts securely if omitted; use only for scripting/tests)}')]
#[Description('Create an invited author (one account = one blog) and seed their About/Links pages')]
class CreateAuthor extends Command
{
    /**
     * The two pages every author starts with.
     *
     * @var array<int, string>
     */
    protected array $defaultPages = ['about', 'links'];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = $this->option('name') ?: text(
            label: 'Display name',
            placeholder: 'Brian Goodwin',
            required: true,
        );

        $username = $this->option('username') ?: text(
            label: 'Username (immutable, used in URLs)',
            placeholder: 'brian',
            required: true,
            hint: 'Lowercase letters, numbers, and underscores only.',
        );

        $email = $this->option('email') ?: text(
            label: 'Email (used to log in)',
            placeholder: 'brian@example.com',
            required: true,
        );

        $plainPassword = $this->option('password') ?: password(
            label: 'Password',
            required: true,
            validate: fn (string $value) => strlen($value) < 8
                ? 'The password must be at least 8 characters.'
                : null,
        );

        $validator = Validator::make(
            [
                'name' => $name,
                'username' => $username,
                'email' => $email,
                'password' => $plainPassword,
            ],
            [
                'name' => ['required', 'string', 'max:255'],
                'username' => ['required', 'string', 'lowercase', 'regex:/^[a-z0-9_]+$/', 'max:30', 'unique:users,username'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        // Create the user and seed their pages atomically: if anything fails,
        // we don't want a half-created author with no pages.
        $user = DB::transaction(function () use ($name, $username, $email, $plainPassword): User {
            $user = User::create([
                'name' => $name,
                'username' => $username,
                'email' => $email,
                'password' => $plainPassword, // hashed by the model's 'hashed' cast
            ]);

            foreach ($this->defaultPages as $slug) {
                $user->pages()->create([
                    'slug' => $slug,
                    'body' => '',
                ]);
            }

            return $user;
        });

        $this->info("Author '{$user->username}' created (id {$user->id}) with About and Links pages.");

        return self::SUCCESS;
    }
}
