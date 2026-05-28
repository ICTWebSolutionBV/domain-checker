<?php

namespace App\Http\Controllers;

use App\Services\RedirectCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RedirectCheckController extends Controller
{
    public function __construct(
        private readonly RedirectCheckService $service,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('RedirectChecker', [
            'initialUrl'      => $request->query('url', ''),
            'userAgentOptions' => RedirectCheckService::userAgentOptions(),
        ]);
    }

    public function check(Request $request): JsonResponse
    {
        $request->validate([
            'url'        => ['required', 'string', 'max:2048'],
            'user_agent' => ['nullable', 'string', 'max:64'],
        ]);

        $url = trim((string) $request->string('url'));
        $ua  = (string) $request->string('user_agent', 'default');

        $result = $this->service->check($url, $ua);

        return response()->json($result);
    }
}
