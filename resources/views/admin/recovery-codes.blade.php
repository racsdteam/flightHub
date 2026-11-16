<x-layouts.app title="Recovery codes">
    <section class="card auth-card wide">
        <div class="auth-head"><x-icon name="key" size="28" /><h1>Recovery codes</h1><x-chip tone="stale" icon="warning">Shown once</x-chip></div>
        <div class="banner banner-stale"><x-icon name="warning" size="20" /><p><span class="banner-title-sm">These codes will not be shown again.</span>
            Each code signs you in once if you lose your authenticator. <strong>They are shown only now:</strong> store them somewhere safe and offline.</p></div>
        <ol class="codes" id="recovery-codes">
            @foreach ($codes as $code)
                <li>{{ $code }}</li>
            @endforeach
        </ol>
        <div class="actions">
            <button type="button" class="button button-secondary" data-copy="recovery-codes"><x-icon name="copy" /> Copy codes</button>
            <button type="button" class="button button-secondary" data-download="recovery-codes" data-filename="rac-flight-data-hub-recovery-codes.txt"><x-icon name="download" /> Download codes (.txt)</button>
        </div>
        <div class="form-actions"><a class="button button-block" href="{{ route('password.change') }}">I have stored them — continue</a></div>
    </section>
</x-layouts.app>
