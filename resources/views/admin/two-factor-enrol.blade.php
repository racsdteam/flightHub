<x-layouts.app title="Set up your authenticator">
    <section class="card auth-card wide">
        <div class="auth-head"><x-icon name="shield" size="28" /><h1>Set up your authenticator</h1></div>
        <p class="muted">An authenticator code is required for every sign-in. Set it up now with an authenticator app on your phone.</p>
        <div class="enrol">
            <div class="qr" aria-label="QR code for your authenticator app">{!! $qr !!}</div>
            <div>
                <ol class="steps">
                    <li>Open your authenticator app.</li>
                    <li>Add an account and scan the QR code.</li>
                    <li>Or enter the secret key below by hand.</li>
                </ol>
                <p class="label">Secret key (manual entry)</p>
                <div class="copy-field">
                    <span class="secret-value"><code id="totp-secret">{{ $secret }}</code></span>
                    <button type="button" class="button button-secondary" data-copy="totp-secret"><x-icon name="copy" /> Copy</button>
                </div>
            </div>
        </div>
        <form method="POST" action="{{ route('two-factor.confirm') }}" novalidate>
            @csrf
            <div class="field">
                <label for="code">Verification code from the app</label>
                <div class="input-icon"><x-icon name="key" /><input id="code" name="code" class="mono" inputmode="numeric" autocomplete="one-time-code" placeholder="6-digit code" autofocus></div>
                @error('code') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="button button-block">Verify and turn on two-factor sign-in</button>
        </form>
    </section>
</x-layouts.app>
