<x-layouts.app title="Sign in">
    <section class="card auth-card">
        <div class="auth-head"><x-icon name="lock" size="28" /><h1>Sign in</h1></div>
        <p class="auth-step">Step 1 of 2 · then the 6-digit code from your authenticator app</p>
        @if (request()->query('session') === 'ended')
            <div class="banner banner-warning" role="status"><x-icon name="warning" size="20" /><p>Your session has ended. Please sign in again.</p></div>
        @endif
        <div class="banner banner-info"><x-icon name="info" size="20" /><p>Admins and data stewards sign in with their email and password, then an authenticator-app code.</p></div>
        <form method="POST" action="{{ route('login.store') }}" novalidate>
            @csrf
            <div class="field">
                <label for="email">Email</label>
                <div class="input-icon"><x-icon name="user" /><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" placeholder="Your RAC email" required autofocus></div>
                @error('email') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="password">Password</label>
                <div class="input-icon"><x-icon name="lock" /><input id="password" name="password" type="password" autocomplete="current-password" placeholder="Your password" required></div>
                @error('password') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="button button-block">Continue</button>
        </form>
        <p class="auth-foot">After 5 failed attempts an account is locked for 15 minutes.</p>
    </section>
</x-layouts.app>
