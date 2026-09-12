<?php

namespace Tests\Unit;

use App\Services\WhoisService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class WhoisServiceTest extends TestCase
{
    public function test_not_available_phrase_does_not_mark_domain_available(): void
    {
        $this->assertSame('taken', $this->parseAvailability(
            "Domain Name: EXAMPLE.COM\nRegistrar: Example Registrar\nThe domain is not available.\n",
        ));
    }

    public function test_no_match_response_marks_domain_available(): void
    {
        $this->assertSame('available', $this->parseAvailability('No match for "EXAMPLE.TEST"'));
    }

    /**
     * Every one of these bodies is a real registry answer for a free domain and
     * every one of them echoes the queried name on a "Domain:" line. The old
     * parser checked for that line before it looked at the status, so .be, .de,
     * .eu and .it reported free domains as taken.
     */
    #[DataProvider('freeRegistryResponses')]
    public function test_registry_answers_for_a_free_domain_are_read_as_available(string $tld, string $body): void
    {
        $this->assertSame('available', $this->parseAvailability($body), "{$tld} should be read as available");
    }

    public static function freeRegistryResponses(): array
    {
        return [
            'be' => ['be', "Domain:\tkayzzq-test-9182.be\nStatus:\tAVAILABLE\n"],
            'de' => ['de', "Domain: kayzzq-test-9182.de\nStatus: free\n"],
            'eu' => ['eu', "Domain: kayzzq-test-9182.eu\nScript: LATIN\nStatus: AVAILABLE\n"],
            'it' => ['it', "Domain:             kayzzq-test-9182.it\nStatus:             AVAILABLE\n"],
            'uk' => ['uk', "No match for \"kayzzq-test-9182.uk\".\nThis domain name has not been registered.\n"],
        ];
    }

    #[DataProvider('registeredRegistryResponses')]
    public function test_registry_answers_for_a_registered_domain_are_read_as_taken(string $tld, string $body): void
    {
        $this->assertSame('taken', $this->parseAvailability($body), "{$tld} should be read as taken");
    }

    public static function registeredRegistryResponses(): array
    {
        return [
            'be' => ['be', "Domain:\tgoogle.be\nStatus:\tNOT AVAILABLE\nRegistered:\tTue Dec 12 2000\n"],
            'de' => ['de', "Domain: google.de\nStatus: connect\n"],
            'eu' => ['eu', "Domain: google.eu\nRegistrant:\n        Organisation: Google LLC\n"],
            'it' => ['it', "Domain:             google.it\nStatus:             ok\nCreated:            1999-12-10\n"],
            'uk' => ['uk', "Domain name:\ngoogle.uk\nRegistrar:\nMarkmonitor Inc. [Tag = MARKMONITOR]\n"],
        ];
    }

    /**
     * The costly direction: never turn a negated sentence into "this name is
     * free". A wrong 'taken' loses a lead; a wrong 'available' gets promised to
     * a client and cannot be delivered.
     */
    #[DataProvider('negatedAvailabilityResponses')]
    public function test_negated_availability_is_never_read_as_available(string $body): void
    {
        $this->assertNotSame('available', $this->parseAvailability($body));
    }

    public static function negatedAvailabilityResponses(): array
    {
        return [
            ['This domain name is not available for registration.'],
            ['Domain: example.test'."\n".'This name is no longer available for registration.'],
            ['example.test cannot be registered.'],
        ];
    }

    public function test_unparseable_response_is_unknown_rather_than_a_guess(): void
    {
        $this->assertSame('unknown', $this->parseAvailability(
            "Requests of this client are not permitted. Please use https://www.nic.ch/whois/ for queries.\n",
        ));
        $this->assertSame('unknown', $this->parseAvailability(''));
    }

    private function parseAvailability(string $response): string
    {
        $method = new ReflectionMethod(WhoisService::class, 'parseAvailability');

        return $method->invoke(new WhoisService, $response);
    }
}
