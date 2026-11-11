<?php

namespace App\Collector;

use Carbon\CarbonImmutable;

/**
 * Rotations (hub-owned): arrival → next departure, departure → previous
 * arrival. Pure: flight snapshots keyed by aodb_id in, link changes out.
 *
 * 1. linkedId (on either side) to a known in-feed flight of the other flow →
 *    that link, source aodb. AODB links always win.
 * 2. Otherwise, by registration: an arrival with reg R links to the first
 *    in-feed departure with reg R whose reference_time is after the arrival's
 *    in-block time (aibt, else eibt, else sibt) and within the limit (24 h),
 *    unless another arrival of R blocks in between. Source registration.
 * 3. A link that no longer holds is removed.
 *
 * Only flights in the poll window are re-linked; a flight outside it changes
 * only when a window flight links to it or stops linking to it.
 */
final class RotationLinker
{
    public function __construct(private readonly int $maxHours) {}

    /**
     * @param  array<string, array{flow: ?string, reg: ?string, linked_id: ?string, reference_time: ?string, aibt: ?string, eibt: ?string, sibt: ?string, in_feed: bool, next: ?string, prev: ?string, source: ?string}>  $flights
     * @param  array<string, true>  $window  aodb_ids of the flights in the poll window
     * @return array<string, array{field: string, from: ?string, to: ?string, source: ?string}> aodb_id → change
     */
    public function changes(array $flights, array $window): array
    {
        $live = array_filter($flights, fn (array $f) => $f['in_feed']);
        [$next, $prev, $source] = $this->desired($live, $window);

        $changes = [];
        foreach ($flights as $id => $flight) {
            $field = match ($flight['flow']) {
                'ARR' => 'next',
                'DEP' => 'prev',
                default => null,
            };
            if ($field === null) {
                continue;
            }

            $desired = $field === 'next' ? ($next[$id] ?? null) : ($prev[$id] ?? null);
            $current = $flight[$field];

            if (isset($window[$id]) || $desired !== null) {
                $target = $desired;
            } elseif ($current !== null && isset($window[$current])) {
                $target = null;
            } else {
                continue;
            }

            $targetSource = $target === null ? null : $source[$id];
            if ($target === $current && $targetSource === $flight['source']) {
                continue;
            }

            $changes[$id] = ['field' => $field, 'from' => $current, 'to' => $target, 'source' => $targetSource];
        }

        return $changes;
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, string>, 2: array<string, string>} next, prev, source
     */
    private function desired(array $live, array $window): array
    {
        $next = [];
        $prev = [];
        $source = [];

        foreach ($live as $id => $flight) {
            $other = $flight['linked_id'] === null ? null : ($live[$flight['linked_id']] ?? null);
            if ($other === null || $other['flow'] === $flight['flow'] || ! in_array($flight['flow'], ['ARR', 'DEP'], true)) {
                continue;
            }
            [$arr, $dep] = $flight['flow'] === 'ARR' ? [$id, $flight['linked_id']] : [$flight['linked_id'], $id];
            if ((isset($window[$arr]) || isset($window[$dep])) && ! isset($next[$arr]) && ! isset($prev[$dep])) {
                $next[$arr] = $dep;
                $prev[$dep] = $arr;
                $source[$arr] = $source[$dep] = 'aodb';
            }
        }

        $arrivals = [];
        foreach ($live as $id => $flight) {
            $inBlock = $flight['aibt'] ?? $flight['eibt'] ?? $flight['sibt'];
            if ($flight['flow'] === 'ARR' && $flight['reg'] !== null && $inBlock !== null) {
                $arrivals[$id] = CarbonImmutable::parse($inBlock, 'UTC');
            }
        }

        foreach ($arrivals as $id => $inBlock) {
            if (! isset($window[$id]) || isset($next[$id])) {
                continue;
            }
            $reg = $live[$id]['reg'];
            $limit = $inBlock->addHours($this->maxHours);

            $first = null;
            foreach ($live as $depId => $dep) {
                if ($dep['flow'] !== 'DEP' || $dep['reg'] !== $reg || $dep['reference_time'] === null || isset($prev[$depId])) {
                    continue;
                }
                $time = CarbonImmutable::parse($dep['reference_time'], 'UTC');
                if ($time->gt($inBlock) && $time->lte($limit) && ($first === null || $time->lt($first[1]))) {
                    $first = [$depId, $time];
                }
            }
            if ($first === null) {
                continue;
            }

            foreach ($arrivals as $otherId => $otherInBlock) {
                if ($otherId !== $id && $live[$otherId]['reg'] === $reg && $otherInBlock->gt($inBlock) && $otherInBlock->lt($first[1])) {
                    continue 2;
                }
            }

            $next[$id] = $first[0];
            $prev[$first[0]] = $id;
            $source[$id] = $source[$first[0]] = 'registration';
        }

        return [$next, $prev, $source];
    }
}
