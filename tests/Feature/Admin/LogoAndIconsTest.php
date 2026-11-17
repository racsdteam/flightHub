<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\File;
use Tests\Feature\Collector\PollTestCase;

/**
 * D-053: RAC's logo on a white tile in every top bar (app shell, sign-in steps, error pages,
 * /api/docs), the larger logo above the card on sign-in and error pages, the favicon and
 * apple-touch icon in every page head; the old drawn mark is gone.
 */
class LogoAndIconsTest extends PollTestCase
{
    use AdminHelpers;

    private const LAYOUTS = ['components/layouts/app.blade.php', 'errors/layout.blade.php', 'api-docs.blade.php'];

    public function test_the_logo_files_exist_and_are_square_pngs(): void
    {
        $this->assertSame('4b0fab564d80be39d9be45474e41af7cb83ae40228b70546546510cd40be7008', hash_file('sha256', public_path('images/rac-logo.png')), 'the original, unchanged');
        foreach (['rac-logo.png' => 500, 'rac-logo-80.png' => 80, 'rac-logo-240.png' => 240, 'favicon-32.png' => 32, 'apple-touch-icon-180.png' => 180] as $file => $size) {
            [$width, $height, $type] = getimagesize(public_path('images/'.$file));
            $this->assertSame([$size, $size, IMAGETYPE_PNG], [$width, $height, $type], $file);
        }
        $this->assertFileDoesNotExist(public_path('rac.png'));
    }

    /**
     * Batch 10b: /favicon.ico (served as a static file, for responses that declare no icon) is
     * the RAC icon made with sips from images/favicon-32.png, no longer Laravel's empty file.
     */
    public function test_favicon_ico_is_the_rac_icon(): void
    {
        $ico = (string) file_get_contents(public_path('favicon.ico'));
        $this->assertNotSame('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', hash('sha256', $ico), 'not the empty skeleton file');
        $this->assertSame("\x00\x00\x01\x00\x01\x00", substr($ico, 0, 6), 'an ICO file with one image');
        $this->assertSame([32, 32], [ord($ico[6]), ord($ico[7])], '32 x 32');
        $this->assertSame(32, unpack('v', substr($ico, 12, 2))[1], '32 bits per pixel (with alpha)');
        $this->assertSame('71b9510c6cb17bb05661fefb230e12dc87f310b4a85e742f5ca4bbe400369d09', hash('sha256', $ico));
    }

    public function test_every_layout_references_the_logo_and_the_icons(): void
    {
        foreach (self::LAYOUTS as $layout) {
            $blade = (string) file_get_contents(resource_path('views/'.$layout));
            $this->assertStringContainsString('images/rac-logo-80.png', $blade, $layout);
            $this->assertStringContainsString('alt="Rwanda Airports Company"', $blade, $layout);
            $this->assertStringContainsString('Flight Data Hub</span>', $blade, $layout);
            $this->assertStringContainsString('rel="icon" type="image/png" sizes="32x32"', $blade, $layout);
            $this->assertStringContainsString('images/favicon-32.png', $blade, $layout);
            $this->assertStringContainsString('rel="apple-touch-icon"', $blade, $layout);
        }
        foreach (['components/layouts/app.blade.php', 'errors/layout.blade.php'] as $layout) {
            $this->assertStringContainsString('images/rac-logo-240.png', (string) file_get_contents(resource_path('views/'.$layout)), $layout);
        }
    }

    public function test_no_reference_to_the_old_mark_remains(): void
    {
        $files = collect(File::allFiles(resource_path('views')))->map->getPathname()->push(public_path('css/hub.css'));
        foreach ($files as $file) {
            $text = (string) file_get_contents($file);
            $this->assertStringNotContainsString('brand-mark', $text, $file);
            $this->assertDoesNotMatchRegularExpression('/name="mark"|\'mark\'\s*=>/', $text, $file);
        }
    }

    public function test_rendered_pages_show_the_logo_and_the_favicon(): void
    {
        $this->poll();
        $pages = ['/login' => $this->get('/login')->assertOk(), '/api/docs' => $this->get('/api/docs')->assertOk()];
        $this->actingAs($this->user());
        $pages['/'] = $this->get('/')->assertOk();
        $pages['404'] = $this->get('/flights/no-such-flight')->assertNotFound();

        foreach ($pages as $name => $response) {
            $html = $response->getContent();
            $this->assertMatchesRegularExpression('#<span class="brand-tile"><img src="[^"]*/images/rac-logo-80\.png" width="40" height="40" alt="Rwanda Airports Company"></span>#', $html, $name);
            $this->assertMatchesRegularExpression('#<link rel="icon" type="image/png" sizes="32x32" href="[^"]*/images/favicon-32\.png">#', $html, $name);
        }
        $this->assertStringContainsString('images/rac-logo-240.png', $pages['/login']->getContent());
        $this->assertStringContainsString('images/rac-logo-240.png', $pages['404']->getContent());
        $this->assertStringNotContainsString('images/rac-logo-240.png', $pages['/']->getContent(), 'the shell shows the top-bar logo only');
    }
}
