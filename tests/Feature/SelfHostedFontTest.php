<?php

namespace Tests\Feature;

use Tests\TestCase;

class SelfHostedFontTest extends TestCase
{
    /**
     * The webfont used to come from fonts.bunny.net, which meant the page
     * could not render without a third party, and every navigation told that
     * third party where the visitor was. The Referrer-Policy added earlier
     * limited what leaked; serving the font ourselves removes the request.
     */
    public function test_the_shell_loads_no_third_party_font_host(): void
    {
        $html = $this->get('/')->assertOk()->content();

        $this->assertStringNotContainsString('fonts.bunny.net', $html);
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringNotContainsString('fonts.gstatic.com', $html);
    }

    public function test_the_policy_names_no_third_party_style_or_font_source(): void
    {
        $policy = (string) $this->get('/')->assertOk()->headers->get('Content-Security-Policy');

        preg_match('/style-src ([^;]+)/', $policy, $style);
        preg_match('/font-src ([^;]+)/', $policy, $font);

        $this->assertStringNotContainsString('bunny', $style[1] ?? '');
        $this->assertStringNotContainsString('bunny', $font[1] ?? '');
    }

    public function test_the_font_faces_point_at_bundled_files(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        // Four weights x two subsets, each with its unicode-range, so a browser
        // only fetches the ranges the page actually uses.
        $this->assertSame(8, substr_count($css, '@font-face'));
        $this->assertSame(8, substr_count($css, 'unicode-range:'));
        $this->assertStringContainsString("url('../fonts/instrument-sans-latin-400-normal.woff2')", $css);
        $this->assertStringNotContainsString('https://fonts.bunny.net', $css);

        foreach (['latin', 'latin-ext'] as $subset) {
            foreach ([400, 500, 600, 700] as $weight) {
                $this->assertFileExists(resource_path("fonts/instrument-sans-{$subset}-{$weight}-normal.woff2"));
            }
        }
    }

    public function test_the_font_license_ships_with_the_font(): void
    {
        $this->assertStringContainsString(
            'SIL Open Font License',
            (string) file_get_contents(resource_path('fonts/OFL.txt')),
        );
    }
}
