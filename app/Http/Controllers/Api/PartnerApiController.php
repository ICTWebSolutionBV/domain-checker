<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\StreamsDomainVerdicts;
use App\Http\Controllers\Controller;
use App\Services\DomainAvailabilityService;
use App\Services\TldRepository;
use App\Support\DomainName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Server-to-server checks for our own sites. Same lookups and the same
 * streams as the public checker; only the input format and the gate differ.
 */
class PartnerApiController extends Controller
{
    use StreamsDomainVerdicts;

    public function __construct(
        private readonly DomainAvailabilityService $availability,
        private readonly TldRepository $tldRepository,
    ) {}

    public function check(Request $request): StreamedResponse
    {
        // Only a string is converted: a JSON array or number has to fail the
        // 'string' rule below, not become "Array" or "12" first.
        if (is_string($request->input('domain'))) {
            $request->merge(['domain' => DomainName::toAscii($request->input('domain'))]);
        }

        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:63', 'regex:'.DomainName::LABEL_PATTERN],
            // Either a list of extensions or scope=all, never both or neither.
            'tlds' => ['required_without:scope', 'prohibits:scope', 'array', 'max:100'],
            'tlds.*' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9][a-z0-9-]*$/i'],
            'scope' => ['required_without:tlds', 'string', 'in:all'],
        ]);

        // scope=all is the list the public "All extensions" mode sends.
        $tlds = isset($validated['scope'])
            ? $this->tldRepository->getAllTlds()
            : array_map('strtolower', $validated['tlds']);

        // Deduped, or a repeated TLD inflates 'total' and progress never ends.
        return $this->verdictStream($this->availability, strtolower($validated['domain']), array_values(array_unique($tlds)));
    }

    public function bulkCheck(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'domains' => ['required', 'array', 'max:'.(int) config('domain-checker.partner_api.bulk_max_domains')],
            'domains.*' => ['required', 'string', 'max:253'],
        ]);

        $known = array_flip($this->tldRepository->getAllTlds());
        $domains = [];
        $invalid = [];

        // Parsed like the public bulk check, except that a line which is no
        // domain, or whose extension no registry knows, is answered 'invalid'
        // instead of being dropped or checked under a made-up TLD.
        foreach ($validated['domains'] as $line) {
            $domain = DomainName::fromInput($line);
            $split = $domain === null ? null : $this->tldRepository->splitDomain($domain);

            if ($domain === null || $split === null || ! isset($known[$split['tld']])) {
                $invalid[] = $line;
            } else {
                $domains[$domain][] = $line;
            }
        }

        return $this->bulkVerdictStream($this->availability, $this->tldRepository, $domains, $invalid);
    }

    public function tlds(): JsonResponse
    {
        return response()->json([
            'popular' => $this->tldRepository->getPopularTlds(),
            'all' => $this->tldRepository->getAllTlds(),
        ]);
    }
}
