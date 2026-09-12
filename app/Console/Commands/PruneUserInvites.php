<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserInvite;
use Illuminate\Console\Command;

class PruneUserInvites extends Command
{
    protected $signature = 'invites:prune';

    protected $description = 'Delete invites that have been accepted or whose email already has an account';

    /**
     * The admin user index used to run this DELETE itself, on a GET request.
     * It re-fired on every refresh, Inertia partial reload and browser
     * prefetch. The page now filters spent invites out of what it shows and the
     * removal lives here, where it can be scheduled or run by hand.
     */
    public function handle(): int
    {
        $deleted = UserInvite::query()
            ->where(function ($query): void {
                $query->whereNotNull('used_at')
                    ->orWhereIn('email', User::query()->select('email'));
            })
            ->delete();

        $this->info("Pruned {$deleted} spent invite(s).");

        return self::SUCCESS;
    }
}
