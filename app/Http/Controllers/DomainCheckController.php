<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StreamsDomainVerdicts;
use App\Services\DomainAvailabilityService;
use App\Services\TldRepository;
use App\Support\DomainName;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DomainCheckController extends Controller
{
    use StreamsDomainVerdicts;

    public function __construct(
        private readonly DomainAvailabilityService $availability,
        private readonly TldRepository $tldRepository,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Home', [
            'popularTlds' => $this->tldRepository->getPopularTlds(),
        ]);
    }

    public function check(Request $request): StreamedResponse
    {
        $request->merge([
            'domain' => DomainName::toAscii((string) $request->input('domain', '')),
        ]);

        $request->validate([
            'domain' => ['required', 'string', 'max:63', 'regex:'.DomainName::LABEL_PATTERN],
            // The full IANA list is ~8 KB of comma-separated labels; anything an
            // order of magnitude past that is not a browser of ours.
            'tlds' => ['required', 'string', 'max:20000'],
        ]);

        $domain = strtolower(trim($request->string('domain')));
        $tlds = array_filter(
            array_map('strtolower', explode(',', $request->string('tlds'))),
            // A DNS label is at most 63 characters. Without that bound a long
            // label reaches the cache layer as part of the key and a 255-char
            // column rejects it, throwing mid-stream.
            fn ($t) => strlen($t) <= 63 && preg_match('/^[a-z0-9][a-z0-9-]*$/', $t),
        );
        // Dedupe before verdictStream() caps the list, or a repeated TLD
        // inflates 'total' and the progress bar never reaches the end.
        $tlds = array_values(array_unique($tlds));

        return $this->verdictStream($this->availability, $domain, $tlds);
    }
}
