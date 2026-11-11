<?php

namespace App\Console\Commands;

use App\Admin\Audit;
use App\Api\ApiResponses;
use App\Mail\FeedStateMail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Stale-feed alert (D-041): one email to every active admin when the feed
 * turns stale, one when it recovers; never one per run. The last state is in
 * the cache ("ok" when unknown, so a feed that never worked is reported).
 */
class CheckFeedCommand extends Command
{
    public const STATE_KEY = 'hub.feed_state';

    protected $signature = 'hub:check-feed';

    protected $description = 'Email the active admins when the AODB feed turns stale or recovers';

    public function handle(): int
    {
        $feed = ApiResponses::feed();
        $previous = Cache::get(self::STATE_KEY, 'ok');

        if ($feed['feed'] === $previous) {
            $this->line("hub:check-feed: {$feed['feed']} (unchanged).");

            return self::SUCCESS;
        }

        $last = DB::table('polls')->orderByDesc('id')->first(['outcome', 'finished_at']);
        $recipients = User::where('active', true)->where('role', 'admin')->get();
        foreach ($recipients as $admin) {
            Mail::to($admin->email)->send(new FeedStateMail($feed['feed'], $feed['asOf'], $last?->outcome, $last?->finished_at));
        }

        // Stored after sending: a failed send is retried on the next run.
        Cache::forever(self::STATE_KEY, $feed['feed']);
        Audit::record($feed['feed'] === 'stale' ? 'alert.stale_sent' : 'alert.recovery_sent', [
            'recipients' => $recipients->count(),
            'last_good_poll' => $feed['asOf'],
            'last_outcome' => $last?->outcome,
        ], actor: 'hub');

        $this->line("hub:check-feed: {$previous} → {$feed['feed']}; {$recipients->count()} admins emailed.");

        return self::SUCCESS;
    }
}
