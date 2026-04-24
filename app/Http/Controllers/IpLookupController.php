<?php

namespace App\Http\Controllers;

use App\Services\IpLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IpLookupController extends Controller
{
    public function __construct(
        private readonly IpLookupService $service,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('IpLookup', [
            'initialInput' => $request->query('q', ''),
        ]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'max:255'],
        ]);

        $resolved = $this->service->resolve((string) $request->string('q'));

        if ($resolved === null) {
            return response()->json([
                'error' => 'Could not resolve that input to a public IP address.',
            ], 422);
        }

        $result = $this->service->lookup($resolved['ip'], $resolved['hostname']);

        if ($result === null) {
            return response()->json([
                'error' => 'IP lookup failed. Please try again in a moment.',
            ], 502);
        }

        return response()->json(['result' => $result]);
    }
}
