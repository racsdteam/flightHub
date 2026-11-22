<?php

namespace Tests\Feature\BasePath;

/**
 * Batch 7c (D-061): the move to a subdomain is a config change only (FLIGHTHUB_BASE_PATH empty,
 * APP_URL, SESSION_PATH=/): the same pages work at a domain root.
 */
class AtDomainRootTest extends ServedTestCase
{
    protected const APP_URL = 'https://hub.example.test';

    public function test_the_cookies_are_at_the_root_and_the_return_path_needs_no_base_path(): void
    {
        $this->assertSame('', config('hub.base_path'));
        foreach ($this->get($this->at('/login'))->assertOk()->headers->getCookies() as $cookie) {
            $this->assertSame('/', $cookie->getPath(), $cookie->getName());
        }

        $this->get($this->at('/login?session=ended&return='.rawurlencode('/flights?flow=ARR')))->assertOk();
        $this->signIn($this->user())->assertRedirect(self::APP_URL.'/flights?flow=ARR');
    }
}
