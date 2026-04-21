<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => fn () => [
                'user' => $request->user() ? [
                    'id'             => $request->user()->id,
                    'name'           => $request->user()->name,
                    'email'          => $request->user()->email,
                    'role'           => $request->user()->role ?? 'user',
                    'is_admin'       => $request->user()->isAdmin(),
                    'is_super_admin' => $request->user()->isSuperAdmin(),
                    'has_two_factor' => (bool) $request->user()->two_factor_secret,
                    'passkeys_count' => $request->user()->passkeys()->count(),
                ] : null,
            ],
            'flash' => fn () => [
                'status'  => $request->session()->get('status'),
                'success' => $request->session()->get('success'),
                'error'   => $request->session()->get('error'),
            ],
            'ziggy' => fn () => (new Ziggy)->toArray(),
        ]);
    }
}
