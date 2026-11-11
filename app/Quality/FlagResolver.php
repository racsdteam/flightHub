<?php

namespace App\Quality;

use App\Admin\Audit;
use App\Collector\FlightNormaliser;
use App\Collector\Poller;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * A person's resolution of a quality flag (screens in batch 6). The state
 * (open / cleared) stays the hub's; the resolution is the person's.
 *
 * "reported" is a status only (D-034): who, when, a note. "same_as" (only on
 * duplicate_suspected) sets the flight's hub-owned same_as_flight_id to the
 * related flight: a new version (cause manual) and a "hub.sameAs" change event
 * (source manual), under GET_LOCK('flighthub_change_events'). Removing that
 * resolution reverses it the same way.
 *
 * actor: the signed-in user's email when there is one (batch 6a), else the
 * given text (CLI, tests). Every resolution is also written to admin_audit.
 */
final class FlagResolver
{
    public const RESOLUTIONS = ['fixed', 'ignored', 'reported', 'same_as'];

    public function __construct(private readonly int $lockTimeout = 10) {}

    public function resolve(int $flagId, ?string $resolution, string $actor, ?string $note = null): object
    {
        $actor = trim(Auth::user()?->email ?? $actor);
        if ($resolution !== null && ! in_array($resolution, self::RESOLUTIONS, true)) {
            throw new InvalidArgumentException('Resolution must be one of '.implode(', ', self::RESOLUTIONS).', or null.');
        }
        if ($actor === '' || mb_strlen($actor) > 100) {
            throw new InvalidArgumentException('Actor is required (at most 100 characters).');
        }
        if ($note !== null && mb_strlen($note) > 500) {
            throw new InvalidArgumentException('Note is at most 500 characters.');
        }

        $flag = DB::table('quality_flags')->where('id', $flagId)->first()
            ?? throw new InvalidArgumentException("Quality flag {$flagId} not found.");
        if ($resolution === 'same_as' && ($flag->rule !== FlightRules::DUPLICATE_SUSPECTED || $flag->related_flight_id === null)) {
            throw new InvalidArgumentException('same_as is allowed only on duplicate_suspected flags.');
        }

        $sameAsChanges = ($resolution === 'same_as') !== ($flag->resolution === 'same_as');
        $write = fn () => DB::transaction(fn () => $this->write($flag, $resolution, $actor, $note, $sameAsChanges));

        return $sameAsChanges ? $this->withChangeEventsLock($write) : $write();
    }

    private function write(object $flag, ?string $resolution, string $actor, ?string $note, bool $sameAsChanges): object
    {
        $now = CarbonImmutable::now('UTC');
        $at = $now->format('Y-m-d H:i:s.u');

        if ($sameAsChanges) {
            $this->setSameAs($flag, $resolution === 'same_as' ? (int) $flag->related_flight_id : null, $at);
        }

        DB::table('quality_flags')->where('id', $flag->id)->update([
            'resolution' => $resolution,
            'resolved_by' => $resolution === null ? null : $actor,
            'resolved_at' => $resolution === null ? null : $at,
            'resolution_note' => $resolution === null ? null : $note,
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
        QualityEvaluator::event((int) $flag->id, 'resolved', $flag->resolution, $resolution, $actor, $note, $at);
        Audit::record('quality.resolved', [
            'rule' => $flag->rule,
            'field' => $flag->field,
            'resolution' => ['from' => $flag->resolution, 'to' => $resolution],
            'note' => $note,
        ], 'quality_flag', (int) $flag->id, actor: $actor);

        return DB::table('quality_flags')->where('id', $flag->id)->first();
    }

    /**
     * Change the flight's same_as_flight_id: new version (cause manual) and a
     * "hub.sameAs" event whose values are aodb_ids, never internal ids.
     */
    private function setSameAs(object $flag, ?int $target, string $at): void
    {
        $flight = DB::table('flights')->where('id', $flag->flight_id)->first();
        $current = $flight->same_as_flight_id === null ? null : (int) $flight->same_as_flight_id;

        // Reversal only undoes this flag's own link.
        if ($current === $target || ($target === null && $current !== (int) $flag->related_flight_id)) {
            return;
        }

        $aodbId = fn (?int $id) => $id === null ? null : DB::table('flights')->where('id', $id)->value('aodb_id');
        $versionNo = $flight->current_version_no + 1;

        $columns = [];
        foreach ([...FlightNormaliser::versionColumns(), ...FlightNormaliser::HUB_COLUMNS] as $column) {
            $columns[$column] = $flight->{$column};
        }
        $columns['same_as_flight_id'] = $target;

        $versionId = DB::table('flight_versions')->insertGetId($columns + [
            'flight_id' => $flight->id,
            'version_no' => $versionNo,
            'valid_from' => $at,
            'poll_id' => null,
            'cause' => 'manual',
            'in_feed' => $flight->in_feed,
        ]);

        DB::table('flights')->where('id', $flight->id)->update([
            'same_as_flight_id' => $target,
            'current_version_no' => $versionNo,
            'current_version_id' => $versionId,
            'updated_at' => substr($at, 0, 19),
        ]);

        $json = fn (?string $value) => $value === null ? null : json_encode($value, JSON_UNESCAPED_SLASHES);
        DB::table('change_events')->insert([
            'flight_id' => $flight->id,
            'version_id' => $versionId,
            'kind' => 'field_changed',
            'field' => 'hub.sameAs',
            'old_value' => $json($aodbId($current)),
            'new_value' => $json($aodbId($target)),
            'source' => 'manual',
            'source_ref' => 'quality_flags:'.$flag->id,
            'poll_id' => null,
            'observed_at' => $at,
        ]);
    }

    private function withChangeEventsLock(callable $write): object
    {
        $locked = (int) DB::selectOne('SELECT GET_LOCK(?, ?) AS l', [Poller::LOCK, $this->lockTimeout])->l === 1;
        if (! $locked) {
            throw new RuntimeException('The change events lock is busy; try again.');
        }

        try {
            return $write();
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS l', [Poller::LOCK]);
        }
    }
}
