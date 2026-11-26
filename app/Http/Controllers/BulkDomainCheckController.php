<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StreamsDomainVerdicts;
use App\Services\DomainAvailabilityService;
use App\Services\TldRepository;
use App\Support\DomainName;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BulkDomainCheckController extends Controller
{
    use StreamsDomainVerdicts;

    public function __construct(
        private readonly DomainAvailabilityService $availability,
        private readonly TldRepository $tldRepository,
    ) {}

    public function check(Request $request): StreamedResponse
    {
        $request->validate([
            'domains' => ['required', 'string', 'max:5000'],
        ]);

        $raw = str_replace(["\r\n", "\r"], "\n", $request->string('domains'));
        $lines = array_filter(array_map('trim', explode("\n", str_replace(',', "\n", $raw))));

        // Normalised domain => the lines the client sent that reduce to it. A
        // pasted line can carry an invisible character (a zero-width space
        // from a document) that normalising strips; answering under the
        // normalised name left the client's row spinning forever.
        $domains = [];
        foreach ($lines as $line) {
            $domain = DomainName::fromInput($line);

            if ($domain !== null) {
                $domains[$domain][] = $line;
            }
        }

        return $this->bulkVerdictStream($this->availability, $this->tldRepository, array_slice($domains, 0, 50));
    }
}
