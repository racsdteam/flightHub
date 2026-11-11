<x-layouts.app title="Change password">
    <section class="card auth">
        <h1>Change password</h1>
        @if ($forced)
            <p class="muted">Your password was set by an administrator. Choose your own before continuing.</p>
        @endif
        <form method="POST" action="{{ route('password.update') }}" novalidate>
            @csrf
            <input type="text" name="username" value="{{ auth()->user()->email }}" autocomplete="username" hidden>
            <label for="current_password">Current password</label>
            <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
            @error('current_password') <p class="error" role="alert">{{ $message }}</p> @enderror
            <label for="password">New password <span class="muted">(at least 12 characters, not your email)</span></label>
            <input id="password" name="password" type="password" autocomplete="new-password" required>
            <label for="password_confirmation">Repeat the new password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
            @foreach ($errors->get('password') as $message) <p class="error" role="alert">{{ $message }}</p> @endforeach
            <button type="submit" class="button">Save</button>
        </form>
    </section>
</x-layouts.app>
