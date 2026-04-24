<?php

namespace App\Http\Controllers;

use App\Services\Http3CheckService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Http3CheckController extends Controller
{
    public function __construct(
        private readonly Http3CheckService $service,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Http3', [
            'initialHost' => $request->query('host', ''),
        ]);
    }

    public function check(Request $request): StreamedResponse
    {
        $request->validate([
            'host' => ['required', 'string', 'max:253'],
        ]);

        $host = trim($request->string('host'));

        return response()->stream(function () use ($host) {
            set_time_limit(0);
            ignore_user_abort(false);

            // Kill any nested output buffers so our writes reach the client
            // (and any proxy in front of us) immediately. Some deploy setups
            // enable zlib / output_buffering globally which can swallow SSE.
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }

            $emit = function (array $event): void {
                $payload = json_encode($event, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                if ($payload === false) {
                    $payload = json_encode(['type' => 'error', 'detail' => 'encoding-failed']);
                }
                echo 'data: ' . $payload . "\n\n";
                // ob_flush() only works if an output buffer exists; flush()
                // always pushes to SAPI.
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            };

            // Initial heartbeat — opens the pipe through any buffering proxy
            // before any slow work happens.
            echo ": ping\n\n";
            flush();

            try {
                $this->service->check($host, $emit);
            } catch (\Throwable $e) {
                \Log::error('Http3Check stream failed', [
                    'host'      => $host,
                    'exception' => $e,
                ]);
                $emit([
                    'type'    => 'done',
                    'result'  => 'error',
                    'h3'      => false,
                    'summary' => 'Check aborted: ' . $e->getMessage(),
                ]);
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }
}
