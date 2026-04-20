<?php

namespace App\Http\Controllers;

use App\Services\DomainAvailabilityService;
use App\Services\TldRepository;
use Illuminate\Http\Request;
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
            'tlds'   => ['required', 'string'],
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

        return response()->stream(function () use ($domain, $tlds) {
            set_time_limit(0); // SSE streams can run longer than the default 30s

            $total   = count($tlds);
            $checked = 0;

            // streamCheck sends all RTR commands at once (pipelined), emitting each
            // result via the callback as the server responds — no per-batch blocking.
            $this->availability->streamCheck(
                $domain,
                $tlds,
                function (string $tld, string $status) use (&$checked, $total): void {
                    $checked++;
                    echo 'data: '.json_encode(['tld' => $tld, 'status' => $status, 'checked' => $checked, 'total' => $total])."\n\n";
                    ob_flush();
                    flush();
                }
            );

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
