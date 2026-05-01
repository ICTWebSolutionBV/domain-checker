<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class TransferRequestController extends Controller
{
    /**
     * Show the transfer request page.
     *
     * The page is purely client-side: the user fills out one or more
     * transfer blocks (a list of domains plus the registrant details that
     * apply to those domains) and presses "Copy to clipboard" to produce a
     * single formatted summary they can paste into an email or chat.
     */
    public function index(): Response
    {
        return Inertia::render('Transfer');
    }
}
