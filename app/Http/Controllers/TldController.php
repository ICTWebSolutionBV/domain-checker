<?php

namespace App\Http\Controllers;

use App\Services\TldRepository;
use Illuminate\Http\JsonResponse;

class TldController extends Controller
{
    public function __construct(private readonly TldRepository $tldRepository) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'popular' => $this->tldRepository->getPopularTlds(),
            'all' => $this->tldRepository->getAllTlds(),
        ]);
    }
}
