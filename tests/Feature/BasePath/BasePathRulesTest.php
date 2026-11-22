<?php

namespace Tests\Feature\BasePath;

use App\Admin\ReturnPath;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Batch 7c (D-061): the rules that keep the hub working under a base path.
 */
class BasePathRulesTest extends TestCase
{
    /**
     * E2: no root-absolute URL literal in the views, the hub's scripts or its stylesheet; every
     * address goes through url() / asset() / route().
     */
    public function test_no_root_absolute_url_literal_in_views_scripts_or_css(): void
    {
        $views = collect(File::allFiles(resource_path('views')))->filter(fn ($f) => str_ends_with($f->getFilename(), '.blade.php'));
        $this->assertGreaterThan(30, $views->count());
        foreach ($views as $file) {
            $name = $file->getRelativePathname();
            $blade = (string) file_get_contents($file->getPathname());
            $this->assertDoesNotMatchRegularExpression('#\s(?:href|src|action|formaction|srcset|poster|data-[a-z-]+)\s*=\s*"\s*/(?!/)#i', $blade, "{$name}: root-absolute URL attribute");
            $this->assertDoesNotMatchRegularExpression('#=\s*"\{\{\s*[\'"]/(?!/)#', $blade, "{$name}: root-absolute URL echoed");
            // (No CSS in views: inline styles are refused by ViewsCspTest; hub.css is checked below.)
        }

        foreach (File::files(public_path('js')) as $file) {
            $script = (string) file_get_contents($file->getPathname());
            $this->assertDoesNotMatchRegularExpression('#[\'"`]/[A-Za-z0-9_.-]#', $script, $file->getFilename().': root-absolute URL literal');
        }
        foreach (File::files(public_path('css')) as $file) {
            $this->assertDoesNotMatchRegularExpression('#url\(\s*[\'"]?/#i', (string) file_get_contents($file->getPathname()), $file->getFilename().': root-absolute CSS url()');
        }
    }

    /**
     * A5: under /flighthub the browser's path is accepted only under the base path, stripped
     * before the refused-prefix check and before the redirect.
     */
    public function test_the_return_path_under_a_base_path(): void
    {
        config(['hub.base_path' => '/flighthub']);

        foreach ([
            '/flighthub/quality?rule=type_missing' => '/quality?rule=type_missing',
            '/flighthub/flights/8ad28dee-1345-4e86-a9b0-9a0ec98e585a' => '/flights/8ad28dee-1345-4e86-a9b0-9a0ec98e585a',
            '/flighthub' => '/',
            '/flighthub/' => '/',
            '/flighthub?x=1' => '/?x=1',
        ] as $path => $expected) {
            $this->assertSame($expected, ReturnPath::safe($path), $path);
        }

        foreach (['/', '/quality', '/pulse/', '/pulse/dashboard', '/flighthubx/quality', '/FLIGHTHUB/quality', '/flighthub/login', '/flighthub/logout',
            '/flighthub/two-factor-challenge', '/flighthub/livewire-dae5e4c8/update', '/flighthub/LOGIN', '/flighthub//evil.example/x',
            '/flighthub/flighthub', '/flighthub/flighthub/quality', '/flighthub\\evil', 'https://systems.rac.co.rw/flighthub/quality',
            '//systems.rac.co.rw/flighthub/quality', 'flighthub/quality', "/flighthub/flights\nX"] as $path) {
            $this->assertNull(ReturnPath::safe($path), $path);
        }

        // At a domain root (the subdomain move) nothing is stripped.
        config(['hub.base_path' => '']);
        $this->assertSame('/quality?rule=type_missing', ReturnPath::safe('/quality?rule=type_missing'));
        $this->assertSame('/flighthub/quality', ReturnPath::safe('/flighthub/quality'));
    }

    /**
     * E3 / A2: a console run (hub:check-feed sends the alert mails) takes every URL from APP_URL,
     * base path included: the feed alert's link and the prerendered 503 page (artisan down
     * --render). A fresh boot in a child process with APP_URL as in production.
     */
    public function test_console_urls_come_from_app_url_with_the_base_path(): void
    {
        $none = rtrim(sys_get_temp_dir(), '/').'/hub-basepath-none-'.bin2hex(random_bytes(6));
        $process = new Process([PHP_BINARY, '-r',
            'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
            .' (new Illuminate\Foundation\Exceptions\RegisterErrorViewPaths)();'      // as artisan down --render does
            .' echo (new App\Mail\FeedStateMail("stale", null, null, null))->render(), "\n=====\n", view("errors::503")->render();',
        ], base_path(), [
            'APP_ENV' => 'testing',
            'APP_URL' => 'https://systems.rac.co.rw/flighthub',
            'FLIGHTHUB_BASE_PATH' => '/flighthub',
            'APP_CONFIG_CACHE' => $none.'-config.php',
            'APP_ROUTES_CACHE' => $none.'-routes.php',
            'MAIL_MAILER' => 'array',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
        ]);
        $process->setTimeout(60)->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        [$mail, $page] = explode("\n=====\n", $process->getOutput());

        $this->assertStringContainsString('Feed health: https://systems.rac.co.rw/flighthub', $mail);
        foreach (['images/favicon-32.png', 'css/hub.css', 'images/rac-logo-80.png', 'images/rac-logo-240.png'] as $file) {
            $this->assertStringContainsString('"https://systems.rac.co.rw/flighthub/'.$file.'"', $page, $file);
        }
        $this->assertStringContainsString('href="https://systems.rac.co.rw/flighthub"', $page, 'the brand link');
        $this->assertDoesNotMatchRegularExpression('#(?:href|src)="(?!https://systems\.rac\.co\.rw/flighthub)#', $page, 'every address under APP_URL');
    }
}
