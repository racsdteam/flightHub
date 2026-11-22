@if ($state === 'stale')
The nightly database backup is STALE: no backup newer than {{ $hours }} hours in the backup folder.
@else
The nightly database backup has RECOVERED: a backup newer than {{ $hours }} hours is in the backup folder.
@endif

Newest backup: {{ $newestBackup ? \App\Admin\Times::kigali($newestBackup, 'Y-m-d H:i:s').' Kigali time ('.\App\Admin\Times::utc($newestBackup)->format('Y-m-d H:i:s').' UTC)' : 'none' }}

Check scripts/backup.sh in cron and its log (docs/DEPLOY.md, backups).

One email is sent when the backup turns stale and one when it recovers.
