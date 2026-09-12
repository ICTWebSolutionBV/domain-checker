<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Nothing here asserts on the asset bundle, and public/build is a
        // build artefact that is not in git -- so without this, every test
        // that renders a page passes locally and 500s in CI on a missing
        // Vite manifest.
        $this->withoutVite();
    }
}
