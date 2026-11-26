<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreatePartnerApiToken extends Command
{
    protected $signature = 'partner-api:token {name : Client name, e.g. website}';

    protected $description = 'Generate a partner API token and print the PARTNER_API_CLIENTS line for it';

    /**
     * The token is printed once and stored nowhere: only its SHA-256 hash goes
     * into this app's environment, so a leaked .env or config cache cannot be
     * replayed against the API.
     */
    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name)) {
            $this->error('The name may only contain letters, digits, "-" and "_" (it is stored as name:hash, comma separated).');

            return self::FAILURE;
        }

        $token = Str::random(48);
        $entry = $name.':'.hash('sha256', $token);
        $existing = trim((string) config('domain-checker.partner_api.clients'));

        $this->line('Token for "'.$name.'". It is shown only now; put it in the client\'s environment:');
        $this->newLine();
        $this->line('  '.$token);
        $this->newLine();
        $this->line('Set this in the domain checker\'s .env (existing clients are kept):');
        $this->newLine();
        $this->line('  PARTNER_API_CLIENTS='.($existing === '' ? $entry : $existing.','.$entry));

        return self::SUCCESS;
    }
}
