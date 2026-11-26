<?php

namespace App\Console\Commands;

use App\Ashby\Admin\ClassFactory;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Makes an administrator login on any server. The password is random and shown once.
 * An administrator then makes classes and instructor logins on the admin page.
 */
class AshbyAdmin extends Command
{
    protected $signature = 'ashby:admin {--email= : The administrator\'s own email address} {--name=Administrator : The administrator\'s name}';

    protected $description = 'Create an administrator login (random password, shown once)';

    public function handle(ClassFactory $factory): int
    {
        $email = Str::lower(trim((string) $this->option('email')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Give the email with --email=name@example.edu');

            return self::FAILURE;
        }
        try {
            $made = $factory->login((string) $this->option('name'), $email, User::ROLE_ADMIN);
        } catch (RuntimeException) {
            $this->error('That email already has a login.');

            return self::FAILURE;
        }
        $this->info("Administrator login made for {$made['user']->email}.");
        $this->line("Password (shown once): {$made['password']}");

        return self::SUCCESS;
    }
}
