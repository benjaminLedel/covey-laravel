<?php

namespace Covey\Laravel;

use Illuminate\Support\Facades\Log;

// One line per call, always. The point is not forensics after the fact alone:
// a team that can read what an agent asked its database last week trusts the
// next agent more than one that cannot.
class Audit
{
    public static function record(string $action, string $ability, array $context, float $startedAt): void
    {
        $context['action'] = $action;
        $context['ability'] = $ability;
        $context['ms'] = (int) round((microtime(true) - $startedAt) * 1000);
        $channel = config('covey.log_channel');
        $logger = $channel ? Log::channel($channel) : Log::getFacadeRoot();
        $logger->info('covey', $context);
    }
}
