<?php

namespace App\Console\Commands;

use App\Admin\Audit;
use App\Api\ApiResponses;
use App\Mail\FeedStateMail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Stale-feed alert (D-041, D-055): one email to every active admin and to every address in
 * ALERT_EXTRA_RECIPIENTS when the feed turns stale, one when it recovers; never one per run,
 * and one per address (an address listed twice, or also an admin's, gets one mail). An
 * invalid extra address is skipped and logged. The last state is in the cache ("ok" when
 * unknown, so a feed that never worked is reported).
 */
class CheckFeedCommand extends Command
{
    public const STATE_KEY = 'hub.feed_state';

    protected $signature = 'hub:check-feed';

    protected $description = 'Email the active admins and the extra alert recipients when the AODB feed turns stale or recovers';

    public function handle(): int
    {
        $feed = ApiResponses::feed();
        $previous = Cache::get(self::STATE_KEY, 'ok');

        if ($feed['feed'] === $previous) {
            $this->line("hub:check-feed: {$feed['feed']} (unchanged).");

            return self::SUCCESS;
        }

        $last = DB::table('polls')->orderByDesc('id')->first(['outcome', 'finished_at']);
        $admins = User::where('active', true)->where('role', 'admin')->pluck('email')->all();
        $extra = $this->extraRecipients();

        // One mail per address: admins first, then the extra addresses not already mailed.
        $addresses = [];
        foreach ([...$admins, ...$extra] as $address) {
            $addresses[mb_strtolower($address)] ??= $address;
        }
        foreach ($addresses as $address) {
            Mail::to($address)->send(new FeedStateMail($feed['feed'], $feed['asOf'], $last?->outcome, $last?->finished_at));
        }
        $extraMailed = count($addresses) - count(array_unique(array_map('mb_strtolower', $admins)));

        // Stored after sending: a failed send is retried on the next run.
        Cache::forever(self::STATE_KEY, $feed['feed']);
        Audit::record($feed['feed'] === 'stale' ? 'alert.stale_sent' : 'alert.recovery_sent', [
            'recipients' => count($addresses),
            'extra_recipients' => $extraMailed,
            'last_good_poll' => $feed['asOf'],
            'last_outcome' => $last?->outcome,
        ], actor: 'hub');

        $this->line("hub:check-feed: {$previous} → {$feed['feed']}; ".count($admins).' '.Str::plural('admin', count($admins))
            ." and {$extraMailed} extra ".Str::plural('recipient', $extraMailed).' emailed.');

        return self::SUCCESS;
    }

    /**
     * ALERT_EXTRA_RECIPIENTS: comma-separated, empty = none. Read only when a mail is due, so
     * an invalid entry is logged once per change of state, not on every run.
     *
     * @return list<string>
     */
    private function extraRecipients(): array
    {
        $valid = [];
        foreach (explode(',', (string) config('mail.alert_extra_recipients')) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (filter_var($entry, FILTER_VALIDATE_EMAIL) === false) {
                Log::warning('hub:check-feed: an ALERT_EXTRA_RECIPIENTS entry is not a valid email address; skipped.', ['entry' => mb_substr($entry, 0, 100)]);
                $this->warn('hub:check-feed: skipped an invalid ALERT_EXTRA_RECIPIENTS entry (see the log).');

                continue;
            }
            $valid[] = $entry;
        }

        return $valid;
    }
}
