<?php

namespace App\Console\Commands;

use App\Admin\Accounts;
use App\Models\User;
use Illuminate\Console\Command;

class AdminDeactivateCommand extends Command
{
    protected $signature = 'hub:admin-deactivate {email}';

    protected $description = 'Deactivate an admin account and end all its sessions (accounts are never deleted)';

    public function handle(): int
    {
        $user = User::where('email', mb_strtolower(trim((string) $this->argument('email'))))->first();
        if ($user === null || ! $user->active) {
            $this->getOutput()->getErrorStyle()->writeln('hub:admin-deactivate: no active account with that email');

            return self::FAILURE;
        }

        Accounts::deactivate($user, 'cli');
        $this->line("hub:admin-deactivate: {$user->email} deactivated; its sessions are ended.");

        return self::SUCCESS;
    }
}
