<?php

namespace Tests\Feature;

use Tests\TestCase;

class AppShellTest extends TestCase
{
    /**
     * The dark ground used to be painted only by a div inside the Vue app, so
     * between first paint and mount the viewport was the browser's default
     * white and dark mode flashed. The body class is the one part of that fix
     * that is server-rendered, and it only works if it ships before the app
     * bundle does.
     */
    public function test_the_shell_paints_a_themed_background_before_hydration(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('bg-canvas', false);

        $html = $response->getContent();

        $this->assertLessThan(
            strpos($html, '<body'),
            strpos($html, 'classList.add(\'dark\')'),
            'The pre-paint theme script must run before the body is parsed.'
        );
    }
}
