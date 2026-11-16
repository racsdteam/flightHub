<x-layouts.app title="Authenticator code">
    <section class="card auth-card">
        <div class="auth-head"><x-icon name="shield" size="28" /><h1>Authenticator code</h1></div>
        <p class="auth-step">Step 2 of 2</p>
        <p class="muted">Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>
        <form method="POST" action="{{ route('two-factor.login.store') }}" novalidate>
            @csrf
            <div class="field">
                <label for="code">Code</label>
                <div class="input-icon"><x-icon name="key" /><input id="code" name="code" class="mono" inputmode="numeric" autocomplete="one-time-code" placeholder="6-digit code" autofocus></div>
                @error('code') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="recovery_code">Or a recovery code</label>
                <input id="recovery_code" name="recovery_code" class="mono" autocomplete="off">
                @error('recovery_code') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="button button-block">Sign in</button>
        </form>
        <p class="auth-foot">After 5 wrong codes in a row the account is locked for 15 minutes.</p>
    </section>
</x-layouts.app>
