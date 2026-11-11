<?php

namespace App\Console\Commands;

use App\Admin\Accounts;
use App\Models\User;
use Illuminate\Console\Command;

class AdminResetCommand extends Command
{
    protected $signature = 'hub:admin-reset {email}';

    protected $description = 'New temporary password and TOTP reset for an account (e.g. the last admin); prints the password once';

    public function handle(): int
    {
        $user = User::where('email', mb_strtolower(trim((string) $this->argument('email'))))->first();
        if ($user === null) {
            $this->getOutput()->getErrorStyle()->writeln('hub:admin-reset: account not found');

            return self::FAILURE;
        }

        $password = Accounts::reset($user, 'cli');
        $this->getOutput()->getErrorStyle()->writeln("hub:admin-reset: {$user->email} reset (TOTP to enrol again, sessions ended). Temporary password, shown once:");
        $this->line($password);

        return self::SUCCESS;
    }
}
