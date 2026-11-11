<?php

namespace App\Api;

use stdClass;

/**
 * The fields one consumer may receive (D-039): the union of its groups plus
 * the always-given ones. Deny by default: a path in no group is never served.
 */
final class FieldSet
{
    /** @var array<string, true> exact AODB paths */
    private array $exact = [];

    /** @var array<string, true> AODB objects served whole ("x.*") */
    private array $objects = [];

    /** @var list<string> hub fields, without the "hub." prefix */
    private array $hub = [];

    /** @var array<string, true> top-level AODB names, in the order the groups list them */
    private array $order = [];

    /**
     * @param  list<string>  $groups
     */
    public static function forGroups(array $groups): self
    {
        $config = config('api_fields.groups');
        $set = new self;
        foreach (array_unique([...config('api_fields.always'), ...$groups]) as $group) {
            // A group no longer configured grants nothing (deny by default).
            if (! isset($config[$group])) {
                continue;
            }
            foreach ($config[$group] as $path) {
                if (str_starts_with($path, 'hub.')) {
                    $set->hub[] = substr($path, 4);
                } elseif (str_ends_with($path, '.*')) {
                    $set->objects[substr($path, 0, -2)] = true;
                    $set->order[substr($path, 0, -2)] = true;
                } else {
                    $set->exact[$path] = true;
                    $set->order[$path] = true;
                }
            }
        }
        $set->hub = array_values(array_unique($set->hub));

        return $set;
    }

    /**
     * May a field (AODB dotted path or hub.x) be served?
     */
    public function allows(string $path): bool
    {
        if (str_starts_with($path, 'hub.')) {
            return in_array(substr($path, 4), $this->hub, true);
        }
        if (isset($this->exact[$path])) {
            return true;
        }
        foreach (array_keys($this->objects) as $object) {
            if ($path === $object || str_starts_with($path, $object.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Allowed top-level AODB field names, in a stable order. Groups list
     * top-level fields and whole objects only; a nested exact path would never
     * make its parent object served.
     *
     * @return list<string>
     */
    public function topLevel(): array
    {
        return array_values(array_filter(array_keys($this->order), fn (string $path) => ! str_contains($path, '.')));
    }

    /**
     * @return list<string>
     */
    public function hubFields(): array
    {
        return $this->hub;
    }

    /**
     * The allowed part of a record, as received. A whole object ("x.*") is
     * served as is; an exact path is served only when it is not an object (an
     * object there would carry paths in no group).
     *
     * @return array<string, mixed>
     */
    public function filter(stdClass $record, bool $padded): array
    {
        $out = [];
        foreach ($this->topLevel() as $name) {
            $present = property_exists($record, $name);
            $value = $present ? $record->{$name} : null;
            if (isset($this->exact[$name]) && ! isset($this->objects[$name]) && $value instanceof stdClass) {
                $value = null;
            }
            if ($present || $padded) {
                $out[$name] = $value;
            }
        }

        return $out;
    }
}
