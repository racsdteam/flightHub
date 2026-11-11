<?php

namespace App\Console\Commands;

use App\Admin\Accounts;
use Illuminate\Console\Command;
use InvalidArgumentException;

class AdminCreateCommand extends Command
{
    protected $signature = 'hub:admin-create {email} {name} {--role=admin : admin or steward}';

    protected $description = 'Create an admin account; prints a temporary password once (stdout only)';

    public function handle(): int
    {
        try {
            ['user' => $user, 'password' => $password] = Accounts::create(
                (string) $this->argument('email'), (string) $this->argument('name'), (string) $this->option('role'), 'cli',
            );
        } catch (InvalidArgumentException $e) {
            $this->getOutput()->getErrorStyle()->writeln('hub:admin-create: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->getOutput()->getErrorStyle()->writeln("hub:admin-create: {$user->email} ({$user->role}) created. Temporary password, shown once (to be changed at first sign-in, after TOTP enrolment):");
        $this->line($password);

        return self::SUCCESS;
    }
}
