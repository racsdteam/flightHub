<x-layouts.app title="Set up your authenticator">
    <section class="card auth">
        <h1>Set up your authenticator</h1>
        <p class="muted">An authenticator code is required for every sign-in. Scan the code with your authenticator app (or enter the key), then type the 6-digit code it shows.</p>
        <div class="qr" aria-label="QR code for your authenticator app">{!! $qr !!}</div>
        <p class="small">Key: <span class="mono secret">{{ $secret }}</span></p>
        <form method="POST" action="{{ route('two-factor.confirm') }}" novalidate>
            @csrf
            <label for="code">Code from the app</label>
            <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus>
            @error('code') <p class="error" role="alert">{{ $message }}</p> @enderror
            <button type="submit" class="button">Confirm</button>
        </form>
        <form method="POST" action="{{ route('logout') }}" class="inline">
            @csrf
            <button type="submit" class="link">Sign out</button>
        </form>
    </section>
</x-layouts.app>
