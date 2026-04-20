<?php

namespace App\Http\Controllers;

use App\Services\DomainAvailabilityService;
use App\Services\TldRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DomainCheckController extends Controller
{
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
        $request->validate([
            'domain' => ['required', 'string', 'max:63', 'regex:/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/'],
            'tlds' => ['required', 'string'],
        ]);

        $domain = strtolower(trim($request->string('domain')));
        $tlds = array_filter(
            array_map('strtolower', explode(',', $request->string('tlds'))),
            fn ($t) => preg_match('/^[a-z0-9][a-z0-9-]*$/', $t),
        );
        $tlds = array_slice(array_values($tlds), 0, 1500);

        if (empty($tlds)) {
            return response()->stream(function () {
                echo "data: {\"done\":true}\n\n";
                ob_flush();
                flush();
            }, 200, $this->sseHeaders());
        }

        $batchSize = config('domain-checker.batch_size', 10);

        return response()->stream(function () use ($domain, $tlds, $batchSize) {
            foreach (array_chunk($tlds, $batchSize) as $batch) {
                $results = $this->availability->checkBatch($domain, $batch);
                foreach ($results as $tld => $status) {
                    echo 'data: '.json_encode(['tld' => $tld, 'status' => $status])."\n\n";
                    ob_flush();
                    flush();
                }
            }
            echo "data: {\"done\":true}\n\n";
            ob_flush();
            flush();
        }, 200, $this->sseHeaders());
    }

    private function sseHeaders(): array
    {
        return [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ];
    }
}
