<x-layouts.app title="Too many attempts">
    <section class="card auth">
        <h1>Too many sign-in attempts</h1>
        <p>Too many failed sign-in attempts came from your network. Try again in {{ $seconds }} seconds.</p>
        <a class="button" href="{{ route('login') }}">Back to sign-in</a>
    </section>
</x-layouts.app>
