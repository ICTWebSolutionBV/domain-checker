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

    /**
     * Observed in production traffic: a free .es domain came back as *taken*.
     * whois.nic.es answers an unauthorised query with ~2 KB of conditions of
     * use instead of domain data, and prose that long trips a "looks
     * registered" pattern. A registry answering about a domain always echoes
     * the name it was asked about, so no echo means no verdict.
     *
     * The fixture below is synthetic -- the registry blocked further queries
     * before the exact offending line was captured -- but it encodes the rule:
     * registration-shaped text about something other than our domain is not
     * evidence about our domain.
     */
    public function test_a_terms_of_use_notice_is_not_read_as_a_registration(): void
    {
        $notice = <<<'TXT'
            Conditions of use for the whois service via port 43 for .es domains

            Access will only be enabled for IP addresses authorised by Red.es.
            Registrar access is subject to the conditions published by Red.es.
            The service will be limited to the data established by Red.es.
            TXT;

        $this->assertSame('unknown', $this->parseAvailability($notice, 'kzq-1789223358.es'));
    }

    public function test_a_registration_that_echoes_the_domain_is_still_taken(): void
    {
        $body = "Domain: google.be\nStatus: NOT AVAILABLE\nRegistered: Tue Dec 12 2000\n";

        $this->assertSame('taken', $this->parseAvailability($body, 'google.be'));
    }

    public function test_an_answer_about_a_different_domain_is_not_our_verdict(): void
    {
        $body = "Domain: someone-else.be\nRegistrant: Someone Else\n";

        $this->assertSame('unknown', $this->parseAvailability($body, 'ours.be'));
    }

    private function parseAvailability(string $response, string $queriedDomain = ''): string
    {
        $method = new ReflectionMethod(WhoisService::class, 'parseAvailability');

        return $method->invoke(new WhoisService, $response, $queriedDomain);
    }
}
