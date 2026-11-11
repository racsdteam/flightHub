<?php

namespace App\Livewire\Users;

use App\Admin\Accounts;
use App\Livewire\AdminPage;
use App\Models\User;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Title;

/**
 * Admin accounts (manage-users): create (temporary password shown ONCE),
 * change role, deactivate / reactivate, reset (temporary password + TOTP
 * reset). Refused: deactivating yourself; demoting or deactivating the last
 * active admin. Every action is audited (App\Admin\Accounts).
 */
#[Title('Users')]
class UserList extends AdminPage
{
    public bool $creating = false;

    public string $email = '';

    public string $name = '';

    public string $role = 'steward';

    /** Shown in this response only (not a public property, so never persisted). */
    protected ?string $temporaryPassword = null;

    protected ?string $temporaryFor = null;

    protected function ability(): string
    {
        return 'manage-users';
    }

    private function actor(): string
    {
        return (string) auth()->user()->email;
    }

    private function target(int $id): User
    {
        return User::findOrFail($id);
    }

    public function startCreate(): void
    {
        $this->creating = true;
    }

    public function cancelCreate(): void
    {
        $this->creating = false;
        $this->reset('email', 'name', 'role');
        $this->resetValidation();
    }

    public function create(): void
    {
        $this->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', 'in:admin,steward'],
        ]);
        try {
            ['user' => $user, 'password' => $password] = Accounts::create($this->email, $this->name, $this->role, $this->actor());
        } catch (InvalidArgumentException $e) {
            $this->addError('email', $e->getMessage());

            return;
        }
        $this->temporaryPassword = $password;
        $this->temporaryFor = $user->email;
        $this->cancelCreate();
    }

    public function changeRole(int $id, string $role): void
    {
        $user = $this->target($id);
        if ($role !== 'admin' && Accounts::isLastActiveAdmin($user)) {
            session()->flash('error', 'Refused: '.$user->email.' is the last active admin.');

            return;
        }
        if ($user->role !== $role) {
            Accounts::changeRole($user, $role, $this->actor());
            session()->flash('status', "{$user->email} is now ".($role === 'admin' ? 'admin' : 'data steward').'.');
        }
    }

    public function deactivate(int $id): void
    {
        $user = $this->target($id);
        if ($user->id === auth()->id()) {
            session()->flash('error', 'Refused: you cannot deactivate yourself.');

            return;
        }
        if (Accounts::isLastActiveAdmin($user)) {
            session()->flash('error', 'Refused: '.$user->email.' is the last active admin.');

            return;
        }
        if ($user->active) {
            Accounts::deactivate($user, $this->actor());
            session()->flash('status', "{$user->email} deactivated; its sessions are ended.");
        }
    }

    public function reactivate(int $id): void
    {
        $user = $this->target($id);
        if (! $user->active) {
            Accounts::reactivate($user, $this->actor());
            session()->flash('status', "{$user->email} reactivated.");
        }
    }

    public function resetAccount(int $id): void
    {
        $user = $this->target($id);
        $this->temporaryPassword = Accounts::reset($user, $this->actor());
        $this->temporaryFor = $user->email;
    }

    public function render(): View
    {
        return view('livewire.users.list', [
            'users' => User::orderByDesc('active')->orderBy('email')->get(),
            'temporaryPassword' => $this->temporaryPassword,
            'temporaryFor' => $this->temporaryFor,
        ]);
    }
}
