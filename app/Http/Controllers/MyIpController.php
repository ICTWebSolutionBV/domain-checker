<?php

namespace App\Http\Controllers;

use App\Services\IpLookupService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MyIpController extends Controller
{
    public function __construct(
        private readonly IpLookupService $service,
    ) {}

    public function index(Request $request): Response
    {
        $ip = $request->ip();
        $result = null;

        if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
            $hostname = @gethostbyaddr($ip) ?: null;
            if ($hostname === $ip) {
                $hostname = null;
            }
            $result = $this->service->lookup($ip, $hostname);
        }

        return Inertia::render('MyIp', [
            'ip'     => $ip,
            'result' => $result,
        ]);
    }
}
