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

            $this->service->check($host, function (array $event): void {
                echo 'data: ' . json_encode($event) . "\n\n";
                ob_flush();
                flush();
            });
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }
}
