<?php

namespace Tests\Feature\Admin;

use App\Console\Commands\CheckFeedCommand;
use App\Mail\BackupStateMail;
use App\Mail\FeedStateMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Collector\PollTestCase;

/**
 * Batch 7b, Part C (D-058): hub:check-feed also reports a backup older than 26 hours, to the
 * alert recipients, once when it turns stale and once when it recovers.
 */
class BackupAlertTest extends PollTestCase
{
    use AdminHelpers;

    private string $backups;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->backups = sys_get_temp_dir().'/hub-backups-'.bin2hex(random_bytes(6));
        mkdir($this->backups, 0700);
        config(['backup.dir' => $this->backups, 'mail.alert_extra_recipients' => 'it.mailbox@rac.test']);
        $this->user('admin');
        $this->poll();                       // the feed is ok: only backup mails below
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->backups.'/*') ?: []);
        @rmdir($this->backups);
        parent::tearDown();
    }

    private function manifest(CarbonImmutable $at): void
    {
        touch($this->backups.'/flighthub-'.$at->format('Ymd\THis\Z').'.manifest');
    }

    private function checkFeed(): string
    {
        Artisan::call('hub:check-feed');

        return Artisan::output();
    }

    public function test_stale_then_recovered_one_mail_each_and_no_repeat(): void
    {
        $this->manifest(CarbonImmutable::now('UTC')->subHours(27));

        $this->assertStringContainsString('hub:check-feed: backup ok → stale; 1 admin and 1 extra recipient emailed.', $this->checkFeed());
        $this->assertStringContainsString('hub:check-feed: backup stale (unchanged).', $this->checkFeed());
        Mail::assertSent(BackupStateMail::class, 2);
        $this->assertCount(2, Mail::sent(BackupStateMail::class, fn ($m) => $m->state === 'stale'));
        $this->assertCount(1, Mail::sent(BackupStateMail::class, fn ($m) => $m->hasTo('it.mailbox@rac.test')));

        $this->manifest(CarbonImmutable::now('UTC')->subHour());
        $this->assertStringContainsString('hub:check-feed: backup stale → ok;', $this->checkFeed());
        $this->checkFeed();
        Mail::assertSent(BackupStateMail::class, 4);
        $this->assertCount(2, Mail::sent(BackupStateMail::class, fn ($m) => $m->state === 'ok'));
        Mail::assertNotSent(FeedStateMail::class);

        $audit = DB::table('admin_audit')->whereIn('action', ['alert.backup_stale_sent', 'alert.backup_recovery_sent'])->orderBy('id')->get();
        $this->assertSame(['alert.backup_stale_sent', 'alert.backup_recovery_sent'], $audit->pluck('action')->all());
        $this->assertSame(['recipients' => 2, 'extra_recipients' => 1, 'newest_backup' => CarbonImmutable::now('UTC')->subHours(27)->format('Y-m-d\TH:i:s\Z')],
            json_decode($audit[0]->details, true));
    }

    public function test_a_fresh_backup_sends_nothing(): void
    {
        $this->manifest(CarbonImmutable::now('UTC')->subHours(25));

        $this->assertStringContainsString('hub:check-feed: backup ok (unchanged).', $this->checkFeed());
        Mail::assertNotSent(BackupStateMail::class);
    }

    public function test_no_manifest_or_a_missing_folder_is_stale(): void
    {
        touch($this->backups.'/flighthub-20261001T013000Z.sql.gz');     // a dump alone does not count
        $this->checkFeed();
        Mail::assertSent(BackupStateMail::class, fn ($m) => $m->state === 'stale' && $m->newestBackup === null);

        Cache::forget(CheckFeedCommand::BACKUP_STATE_KEY);
        config(['backup.dir' => $this->backups.'/no-such-folder']);
        $this->assertStringContainsString('backup ok → stale', $this->checkFeed());
    }

    public function test_an_empty_key_turns_the_check_off(): void
    {
        config(['backup.dir' => '']);

        $this->assertStringContainsString('hub:check-feed: backup check off (FLIGHTHUB_BACKUP_DIR is empty).', $this->checkFeed());
        Mail::assertNotSent(BackupStateMail::class);
        $this->assertNull(Cache::get(CheckFeedCommand::BACKUP_STATE_KEY));
    }

    public function test_the_mail_holds_the_newest_backup_time_only(): void
    {
        $mail = new BackupStateMail('stale', '2026-10-29T01:30:00Z');

        $mail->assertSeeInText('STALE');
        $mail->assertSeeInText('2026-10-29 03:30:00 Kigali time (2026-10-29 01:30:00 UTC)');
        $mail->assertDontSeeInText($this->backups);
        $this->assertSame('RAC Flight Data Hub: database backup is stale', $mail->envelope()->subject);
    }
}
