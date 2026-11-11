<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * G2: every web route carries an ability middleware (can:…), except sign-in,
 * two-factor and sign-out.
 */
class RouteAbilitiesTest extends TestCase
{
    private const EXEMPT = ['login', 'login.store', 'two-factor.login', 'two-factor.login.store', 'two-factor.enrol', 'two-factor.confirm', 'logout'];

    public function test_every_web_route_has_an_ability_middleware(): void
    {
        $missing = [];
        $exempt = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            if (! in_array('web', $middleware, true)) {
                continue;
            }
            if (in_array($route->getName(), self::EXEMPT, true)) {
                $exempt[] = $route->getName();

                continue;
            }
            if (! collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'can:'))) {
                $missing[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        $this->assertSame([], $missing);
        $this->assertEqualsCanonicalizing(self::EXEMPT, $exempt, 'the exempt routes exist and nothing else is exempt');
    }
}
