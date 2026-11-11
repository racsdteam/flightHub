@if ($state === 'stale')
The AODB feed is STALE: no successful poll for more than 15 minutes.
@else
The AODB feed has RECOVERED: polls are succeeding again.
@endif

Last successful poll: {{ $lastGoodPoll ? \App\Admin\Times::kigali($lastGoodPoll, 'Y-m-d H:i:s').' Kigali time ('.\App\Admin\Times::utc($lastGoodPoll)->format('Y-m-d H:i:s').' UTC)' : 'none' }}
Last poll: {{ $lastOutcome ?? 'none' }}@if ($lastPollAt) at {{ \App\Admin\Times::kigali($lastPollAt, 'Y-m-d H:i:s') }} Kigali time @endif

Feed health: {{ $link }}

One email is sent when the feed turns stale and one when it recovers.
