<?php

namespace Tests\Feature;

use App\Services\RdapService;
use ReflectionMethod;
use Tests\TestCase;

class RdapConcurrencyTest extends TestCase
{
    /**
     * One Identity Digital endpoint answers for .info, .studio, .agency,
     * .digital, .media, .news and a few hundred more. Aiming the whole burst at
     * it returns 429, and a 429 is not a verdict -- so the family quietly
     * turns into 'unknown'. Verified by hand against that endpoint.
     */
    public function test_a_batch_concentrated_on_one_host_is_held_back(): void
    {
        config([
            'domain-checker.concurrency.rdap' => 64,
            'domain-checker.concurrency.rdap_per_host' => 8,
        ]);

        $targets = [];

        foreach (range(1, 40) as $i) {
            $targets["tld{$i}"] = "https://rdap.identitydigital.services/rdap/domain/example.tld{$i}";
        }

        $this->assertSame(8, $this->concurrencyFor($targets));
    }

    public function test_a_batch_spread_over_many_hosts_keeps_the_global_ceiling(): void
    {
        config([
            'domain-checker.concurrency.rdap' => 64,
            'domain-checker.concurrency.rdap_per_host' => 8,
        ]);

        $targets = [];

        foreach (range(1, 40) as $i) {
            $targets["tld{$i}"] = "https://rdap.registry{$i}.example/domain/example.tld{$i}";
        }

        // 40 requests over 40 hosts is one each: nothing to hold back.
        $this->assertSame(40, $this->concurrencyFor($targets));
    }

    public function test_a_small_batch_is_never_inflated(): void
    {
        config(['domain-checker.concurrency.rdap' => 64]);

        $this->assertSame(2, $this->concurrencyFor([
            'nl' => 'https://rdap.sidn.nl/domain/example.nl',
            'com' => 'https://rdap.verisign.com/com/v1/domain/example.com',
        ]));
    }

    private function concurrencyFor(array $targets): int
    {
        $method = new ReflectionMethod(RdapService::class, 'concurrencyFor');

        return $method->invoke(app(RdapService::class), $targets);
    }
}
