<x-layouts.app title="Recovery codes">
    <section class="card auth">
        <h1>Recovery codes</h1>
        <p>Each code signs you in once if you lose your authenticator. <strong>They are shown only now:</strong> store them somewhere safe and offline.</p>
        <ul class="codes mono">
            @foreach ($codes as $code)
                <li>{{ $code }}</li>
            @endforeach
        </ul>
        <a class="button" href="{{ route('password.change') }}">I have stored them — continue</a>
    </section>
</x-layouts.app>
