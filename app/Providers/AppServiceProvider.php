<?php

namespace App\Providers;

use App\Admin\Abilities;
use App\Aodb\AodbClient;
use App\Aodb\AodbConfigurationException;
use App\Aodb\FixtureAodbClient;
use App\Aodb\HttpAodbClient;
use App\Api\ApiResponses;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Features\SupportFileUploads\FilePreviewController;
use Livewire\Features\SupportFileUploads\FileUploadController;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Livewire\Mechanisms\HandleRequests\RequireLivewireHeaders;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AodbClient::class, function ($app) {
            return match (config('aodb.client')) {
                'http' => new HttpAodbClient(config('aodb')),
                'fixture' => $app->environment('production')
                    ? throw new AodbConfigurationException('AODB_CLIENT=fixture is refused in production')
                    : new FixtureAodbClient(config('aodb.fixture_path')),
                default => throw new AodbConfigurationException('AODB_CLIENT must be http or fixture'),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Abilities::register();

        // D-061: every generated URL (pages, assets, Livewire, redirects, alert mails) comes from
        // APP_URL, base path included (https://systems.rac.co.rw/flighthub), never from the
        // request's Host header or scheme; https in production.
        $root = rtrim((string) config('app.url'), '/');
        URL::forceRootUrl($root);
        URL::forceScheme($this->app->environment('production') ? 'https' : (parse_url($root, PHP_URL_SCHEME) ?: 'http'));

        // The stale-feed banner on every admin screen (D-041).
        View::composer('components.layouts.app', fn ($view) => $view->with('feed', auth()->check() ? ApiResponses::feed() : null));

        // Livewire updates run behind the same checks as the admin pages.
        Livewire::setUpdateRoute(fn ($handle, $path) => Route::post($path, $handle)
            ->middleware(['web', RequireLivewireHeaders::class, 'auth', 'account', 'can:view-hub']));

        // Livewire's signed upload / preview endpoints (unused by the hub) get the same checks.
        $this->app->booted(function () {
            Route::post(EndpointResolver::uploadPath(), [FileUploadController::class, 'handle'])
                ->middleware(['auth', 'account', 'can:view-hub'])->name('livewire.upload-file');
            Route::get(EndpointResolver::previewPath(), [FilePreviewController::class, 'handle'])
                ->middleware(['auth', 'account', 'can:view-hub'])->name('livewire.preview-file');
        });
    }
}
