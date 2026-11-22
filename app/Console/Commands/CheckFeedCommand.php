<?php

namespace App\Console\Commands;

use App\Admin\Audit;
use App\Admin\BackupStatus;
use App\Api\ApiResponses;
use App\Mail\BackupStateMail;
use App\Mail\FeedStateMail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Stale-feed alert (D-041, D-055) and backup alert (D-058): one email to every active admin and
 * to every address in ALERT_EXTRA_RECIPIENTS when the feed (or the backup) turns stale, one when
 * it recovers; never one per run, and one per address (an address listed twice, or also an
 * admin's, gets one mail). An invalid extra address is skipped and logged. Each last state is
 * in the cache ("ok" when unknown, so a feed or backup that never worked is reported). The
 * backup is stale when the newest manifest in FLIGHTHUB_BACKUP_DIR is older than 26 hours or
 * missing; the check is off while that folder is not configured.
 */
class CheckFeedCommand extends Command
{
    public const STATE_KEY = 'hub.feed_state';

    public const BACKUP_STATE_KEY = 'hub.backup_state';

    protected $signature = 'hub:check-feed';

    protected $description = 'Email the active admins and the extra alert recipients when the AODB feed or the backup turns stale or recovers';

    public function handle(): int
    {
        $this->feed();
        $this->backup();

        return self::SUCCESS;
    }

    private function feed(): void
    {
        $feed = ApiResponses::feed();
        $previous = Cache::get(self::STATE_KEY, 'ok');

        if ($feed['feed'] === $previous) {
            $this->line("hub:check-feed: {$feed['feed']} (unchanged).");

            return;
        }

        $last = DB::table('polls')->orderByDesc('id')->first(['outcome', 'finished_at']);
        [$admins, $extraMailed, $total] = $this->send(new FeedStateMail($feed['feed'], $feed['asOf'], $last?->outcome, $last?->finished_at));

        // Stored after sending: a failed send is retried on the next run.
        Cache::forever(self::STATE_KEY, $feed['feed']);
        Audit::record($feed['feed'] === 'stale' ? 'alert.stale_sent' : 'alert.recovery_sent', [
            'recipients' => $total,
            'extra_recipients' => $extraMailed,
            'last_good_poll' => $feed['asOf'],
            'last_outcome' => $last?->outcome,
        ], actor: 'hub');

        $this->line("hub:check-feed: {$previous} → {$feed['feed']}; ".self::emailed($admins, $extraMailed));
    }

    private function backup(): void
    {
        $stale = BackupStatus::stale();
        if ($stale === null) {
            $this->line('hub:check-feed: backup check off (FLIGHTHUB_BACKUP_DIR is empty).');

            return;
        }
        $state = $stale ? 'stale' : 'ok';
        $previous = Cache::get(self::BACKUP_STATE_KEY, 'ok');

        if ($state === $previous) {
            $this->line("hub:check-feed: backup {$state} (unchanged).");

            return;
        }

        $newest = BackupStatus::newest()?->format('Y-m-d\TH:i:s\Z');
        [$admins, $extraMailed, $total] = $this->send(new BackupStateMail($state, $newest));

        Cache::forever(self::BACKUP_STATE_KEY, $state);
        Audit::record($state === 'stale' ? 'alert.backup_stale_sent' : 'alert.backup_recovery_sent', [
            'recipients' => $total,
            'extra_recipients' => $extraMailed,
            'newest_backup' => $newest,
        ], actor: 'hub');

        $this->line("hub:check-feed: backup {$previous} → {$state}; ".self::emailed($admins, $extraMailed));
    }

    /**
     * One mail per address: the active admins first, then the extra addresses not already mailed.
     *
     * @return array{0: int, 1: int, 2: int} admins, extra recipients mailed, total
     */
    private function send(Mailable $mail): array
    {
        $admins = User::where('active', true)->where('role', 'admin')->pluck('email')->all();
        $addresses = [];
        foreach ([...$admins, ...$this->extraRecipients()] as $address) {
            $addresses[mb_strtolower($address)] ??= $address;
        }
        foreach ($addresses as $address) {
            Mail::to($address)->send(clone $mail);
        }

        return [count($admins), count($addresses) - count(array_unique(array_map('mb_strtolower', $admins))), count($addresses)];
    }

    private static function emailed(int $admins, int $extra): string
    {
        return "{$admins} ".Str::plural('admin', $admins)." and {$extra} extra ".Str::plural('recipient', $extra).' emailed.';
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
