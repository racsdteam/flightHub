<x-layouts.app title="Change password">
    <section class="card auth-card wide">
        <div class="auth-head"><x-icon name="key" size="28" /><h1>Change password</h1></div>
        @if (session('status'))<div class="banner banner-ok" role="status"><x-icon name="check-circle" size="20" /><p>{{ session('status') }}</p></div>@endif
        @if ($forced)
            <div class="banner banner-info"><x-icon name="info" size="20" /><p>Your password was set by an administrator. Choose your own before continuing.</p></div>
        @endif
        <div class="password-grid">
            <form method="POST" action="{{ route('password.update') }}" novalidate>
                @csrf
                <input type="text" name="username" value="{{ auth()->user()->email }}" autocomplete="username" hidden>
                <div class="field">
                    <label for="current_password">Current password</label>
                    <div class="input-icon"><x-icon name="lock" /><input id="current_password" name="current_password" type="password" autocomplete="current-password" required></div>
                    @error('current_password') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="password">New password</label>
                    <div class="input-icon"><x-icon name="lock" /><input id="password" name="password" type="password" autocomplete="new-password" aria-describedby="password-rules" required></div>
                    @foreach ($errors->get('password') as $message) <p class="field-error" role="alert">{{ $message }}</p> @endforeach
                </div>
                <div class="field">
                    <label for="password_confirmation">Repeat the new password</label>
                    <div class="input-icon"><x-icon name="lock" /><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required></div>
                </div>
                <button type="submit" class="button button-block">Save new password</button>
            </form>
            <aside class="rules" id="password-rules">
                <h2><x-icon name="info" /> Password rules</h2>
                <ul>
                    <li><x-icon name="check" size="14" /> At least 12 characters</li>
                    <li><x-icon name="check" size="14" /> Not your email address</li>
                    <li><x-icon name="check" size="14" /> Different from your current password</li>
                </ul>
            </aside>
        </div>
    </section>
</x-layouts.app>
