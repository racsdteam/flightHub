<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Static CSP check of every Blade view (D-052, F2/F3): no inline style
 * attribute or <style> block, no inline script or event handler, no
 * javascript: URL and no outside URL; the hub's own scripts are only
 * public/js/hub.js and public/js/api-docs.js.
 */
class ViewsCspTest extends TestCase
{
    public function test_no_view_has_inline_styles_scripts_handlers_or_outside_urls(): void
    {
        $views = collect(File::allFiles(resource_path('views')))->filter(fn ($f) => str_ends_with($f->getFilename(), '.blade.php'));
        $this->assertGreaterThan(30, $views->count());

        foreach ($views as $file) {
            $name = $file->getRelativePathname();
            $blade = (string) file_get_contents($file->getPathname());

            $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $blade, "{$name}: inline style attribute");
            $this->assertDoesNotMatchRegularExpression('/<style\b/i', $blade, "{$name}: <style> block");
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $blade, "{$name}: inline event handler");
            $this->assertDoesNotMatchRegularExpression('/javascript:/i', $blade, "{$name}: javascript: URL");
            $this->assertDoesNotMatchRegularExpression('#(?:https?:)?//[a-z0-9.-]+\.[a-z]{2,}#i', $blade, "{$name}: outside URL");
            preg_match_all('/<script\b[^>]*>/i', $blade, $scripts);
            foreach ($scripts[0] as $tag) {
                $this->assertMatchesRegularExpression('/\ssrc="/', $tag, "{$name}: inline script");
            }
        }

        $own = collect(File::files(public_path('js')))->map(fn ($f) => $f->getFilename())->sort()->values()->all();
        $this->assertSame(['api-docs.js', 'hub.js'], $own);
        $this->assertDoesNotMatchRegularExpression('#https?://#', (string) file_get_contents(public_path('js/hub.js')));
        $this->assertDoesNotMatchRegularExpression('#https?://#', (string) file_get_contents(public_path('css/hub.css')));
    }
}
