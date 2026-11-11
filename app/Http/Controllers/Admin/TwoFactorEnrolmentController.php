<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;

/**
 * Mandatory TOTP enrolment: QR code and key, confirmation with a first code,
 * then the recovery codes, shown once (single-use; Fortify replaces a used one).
 */
class TwoFactorEnrolmentController
{
    public function show(Request $request, EnableTwoFactorAuthentication $enable): View|RedirectResponse
    {
        $user = $request->user();
        if ($user->hasConfirmedTotp()) {
            return redirect()->route('dashboard');
        }
        if ($user->two_factor_secret === null) {
            $enable($user);
            $user->refresh();
        }

        return view('admin.two-factor-enrol', [
            'qr' => $user->twoFactorQrCodeSvg(),
            'secret' => decrypt($user->two_factor_secret),
        ]);
    }

    /**
     * The recovery codes are rendered in this response only: never stored in
     * the session, never shown again.
     */
    public function confirm(Request $request, ConfirmTwoFactorAuthentication $confirm): View
    {
        $request->validate(['code' => ['required', 'string', 'max:16']]);
        $user = $request->user();

        try {
            $confirm($user, (string) $request->input('code'));
        } catch (ValidationException) {
            // Fortify uses a named error bag; the page shows the default one.
            throw ValidationException::withMessages(['code' => 'That code is not valid. Check the time on your device and try again.']);
        }

        Audit::record('totp.enrolled', [], 'user', $user->id);

        return view('admin.recovery-codes', ['codes' => $user->recoveryCodes()]);
    }
}
