<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Accounts;
use App\Admin\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Password change: at least 12 characters, not the email. No outside
 * breach check (no outside calls at run time).
 */
class PasswordController
{
    public function edit(Request $request): View
    {
        return view('admin.password', ['forced' => $request->user()->must_change_password]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'max:255'],
        ]);
        $user = $request->user();

        if (! Hash::check((string) $request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is not correct.']);
        }
        $problems = Accounts::passwordProblems((string) $request->input('password'), $user->email);
        if (Hash::check((string) $request->input('password'), $user->password)) {
            $problems[] = 'The new password must differ from the current one.';
        }
        if ($problems !== []) {
            throw ValidationException::withMessages(['password' => $problems]);
        }

        $user->forceFill(['password' => (string) $request->input('password'), 'must_change_password' => false])->save();
        $request->session()->regenerate();
        Audit::record('password.changed', [], 'user', $user->id);

        return redirect()->route('dashboard')->with('status', 'Password changed.');
    }
}
