<x-layouts.app title="Sign in">
    <section class="card auth">
        <h1>Sign in</h1>
        <p class="muted">RAC Flight Data Hub · internal</p>
        <form method="POST" action="{{ route('login.store') }}" novalidate>
            @csrf
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            @error('email') <p class="error" role="alert">{{ $message }}</p> @enderror
            @error('password') <p class="error" role="alert">{{ $message }}</p> @enderror
            <button type="submit" class="button">Continue</button>
        </form>
        <p class="muted small">After 5 failed attempts an account is locked for 15 minutes.</p>
    </section>
</x-layouts.app>
