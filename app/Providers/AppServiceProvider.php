<?php

namespace App\Providers;

use App\Aodb\AodbClient;
use App\Aodb\AodbConfigurationException;
use App\Aodb\FixtureAodbClient;
use App\Aodb\HttpAodbClient;
use Illuminate\Support\ServiceProvider;

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
        //
    }
}
