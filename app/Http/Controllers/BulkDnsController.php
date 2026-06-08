<?php

namespace App\Http\Controllers;

use App\Services\BulkDnsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BulkDnsController extends Controller
{
    public function __construct(
        private readonly BulkDnsService $service,
    ) {}

    public function index(): Response
    {
        return Inertia::render('BulkDns');
    }

    public function lookup(Request $request): JsonResponse
    {
        $request->validate([
            'domains'   => ['required', 'array', 'min:1', 'max:100'],
            'domains.*' => ['required', 'string', 'max:2048'],
            'type'      => ['required', Rule::in(['MX', 'NS', 'TXT', 'A', 'AAAA', 'CNAME'])],
        ]);

        $type    = (string) $request->input('type');
        $results = [];
        $ips     = [];

        foreach ((array) $request->input('domains') as $raw) {
            $domain = strtolower(trim((string) $raw));
            $domain = preg_replace('#^https?://#i', '', $domain);
            $domain = preg_replace('#^www\.#i', '', $domain);
            $domain = rtrim($domain, '/');

            if (! $domain || ! preg_match('/^[a-z0-9][a-z0-9\-]*(?:\.[a-z0-9][a-z0-9\-]*)+$/', $domain)) {
                continue;
            }

            $ip      = $this->service->resolveIp($domain);
            $records = $this->service->lookupRecords($domain, $type);

            $results[] = ['domain' => $domain, 'ip' => $ip, 'records' => $records];

            if ($ip) {
                $ips[] = $ip;
            }
        }

        // Batch geo lookup with up to 4 concurrent workers
        $geoMap = $this->service->lookupGeoBatch($ips);

        foreach ($results as &$row) {
            $row['geo'] = $row['ip'] ? ($geoMap[$row['ip']] ?? null) : null;
        }

        return response()->json(['results' => $results]);
    }
}
