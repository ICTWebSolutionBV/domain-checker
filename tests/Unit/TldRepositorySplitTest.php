<?php

namespace Tests\Unit;

use App\Services\TldRepository;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TldRepositorySplitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pin the TLD list so the split never depends on reaching IANA.
        Cache::put('iana_tld_list', ['com', 'nl', 'uk', 'co'], 60);
    }

    public function test_a_subdomain_is_split_on_the_known_tld_not_on_the_first_dot(): void
    {
        $split = app(TldRepository::class)->splitDomain('blog.google.com');

        // The old explode('.', $d, 2) produced TLD "google.com", which no
        // registry knows -- so the check came back available.
        $this->assertSame(['name' => 'blog.google', 'tld' => 'com'], $split);
    }

    public function test_a_plain_domain_still_splits_normally(): void
    {
        $this->assertSame(
            ['name' => 'example', 'tld' => 'nl'],
            app(TldRepository::class)->splitDomain('example.nl'),
        );
    }

    public function test_a_second_level_suffix_keeps_the_registry_tld(): void
    {
        $this->assertSame(
            ['name' => 'example.co', 'tld' => 'uk'],
            app(TldRepository::class)->splitDomain('example.co.uk'),
        );
    }

    public function test_an_unknown_suffix_falls_back_to_the_last_label(): void
    {
        $this->assertSame(
            ['name' => 'example', 'tld' => 'madeuptld'],
            app(TldRepository::class)->splitDomain('example.madeuptld'),
        );
    }

    public function test_a_single_label_cannot_be_split(): void
    {
        $this->assertNull(app(TldRepository::class)->splitDomain('localhost'));
    }
}
