<x-layouts.app title="Authenticator code">
    <section class="card auth">
        <h1>Authenticator code</h1>
        <p class="muted">Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>
        <form method="POST" action="{{ route('two-factor.login.store') }}" novalidate>
            @csrf
            <label for="code">Code</label>
            <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus>
            <label for="recovery_code">Or a recovery code</label>
            <input id="recovery_code" name="recovery_code" autocomplete="off">
            @error('code') <p class="error" role="alert">{{ $message }}</p> @enderror
            @error('recovery_code') <p class="error" role="alert">{{ $message }}</p> @enderror
            <button type="submit" class="button">Sign in</button>
        </form>
    </section>
</x-layouts.app>
