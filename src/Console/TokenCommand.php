<?php

namespace Covey\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

// Mints a token and prints it ONCE, with the hash the application keeps. The
// value goes into covey as the agent's laravel_token; the hash goes into the
// environment. Nothing is written anywhere by this command — a token that is
// stored in two places is stored in one place too many.
class TokenCommand extends Command
{
    protected $signature = 'covey:token {ability : read or write}';

    protected $description = 'Mint a covey token for this application and print the hash to configure';

    public function handle(): int
    {
        $ability = strtolower((string) $this->argument('ability'));
        if (! in_array($ability, ['read', 'write'], true)) {
            $this->error('ability must be read or write');

            return self::FAILURE;
        }
        $token = 'covey_'.$ability.'_'.Str::random(48);
        $hash = hash('sha256', $token);
        $env = 'COVEY_'.strtoupper($ability).'_TOKEN_HASH';

        $this->line('Token (store it in covey as laravel_token, it is not shown again):');
        $this->line('  '.$token);
        $this->newLine();
        $this->line('Put the hash into this application\'s environment:');
        $this->line("  $env=$hash");

        return self::SUCCESS;
    }
}
