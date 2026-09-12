<?php

namespace App\Http\Controllers;

use App\Services\DomainAvailabilityService;
use App\Services\TldRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            // The full IANA list is ~8 KB of comma-separated labels; anything an
            // order of magnitude past that is not a browser of ours.
            'tlds'   => ['required', 'string', 'max:20000'],
        ]);

        $domain = strtolower(trim($request->string('domain')));
        $tlds = array_filter(
            array_map('strtolower', explode(',', $request->string('tlds'))),
            // A DNS label is at most 63 characters. Without that bound a long
            // label reaches the cache layer as part of the key and a 255-char
            // column rejects it, throwing mid-stream.
            fn ($t) => strlen($t) <= 63 && preg_match('/^[a-z0-9][a-z0-9-]*$/', $t),
        );
        // Dedupe before the cap, or a repeated TLD inflates 'total' and the
        // progress bar never reaches the end.
        $tlds = array_slice(array_values(array_unique($tlds)), 0, 1500);

        if (empty($tlds)) {
            return response()->stream(function () {
                $this->push('data: '.json_encode(['done' => true]));
            }, 200, $this->sseHeaders());
        }

        return response()->stream(function () use ($domain, $tlds) {
            set_time_limit(0); // SSE streams can run longer than the default 30s

            // Open the pipe before any slow work, so a buffering proxy in front
            // of us does not sit on the response until the first result lands.
            $this->push(': ping');

            $total   = count($tlds);
            $checked = 0;

            try {
                // streamCheck sends all RTR commands at once (pipelined), emitting each
                // result via the callback as the server responds — no per-batch blocking.
                $this->availability->streamCheck(
                    $domain,
                    $tlds,
                    function (string $tld, string $status) use (&$checked, $total): void {
                        $checked++;
                        $this->push('data: '.json_encode(['tld' => $tld, 'status' => $status, 'checked' => $checked, 'total' => $total]));
                    }
                );
            } catch (\Throwable $e) {
                // Whatever happens, the client gets a terminal event: a stream
                // that stops without one leaves the UI spinning forever.
                Log::error('Domain check stream failed', [
                    'domain' => $domain,
                    'checked' => $checked,
                    'total' => $total,
                    'exception' => $e,
                ]);

                $this->push('data: '.json_encode(['error' => 'The check stopped early. Please try again.']));
            } finally {
                $this->push('data: '.json_encode(['done' => true]));
            }
        }, 200, $this->sseHeaders());
    }

    /**
     * Write one SSE frame and get it onto the wire.
     *
     * ob_flush() only works when an output buffer exists -- the CLI SAPI has
     * none and hard-codes output_buffering=0 -- while flush() always pushes to
     * the SAPI.
     */
    private function push(string $line): void
    {
        echo $line."\n\n";

        if (ob_get_level() > 0) {
            @ob_flush();
        }

        flush();
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
