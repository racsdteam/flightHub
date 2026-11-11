<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConsumerUpdateCommand extends Command
{
    protected $signature = 'hub:consumer-update
        {code : The consumer}
        {--groups= : Comma-separated field groups (replaces the current list)}
        {--rate= : Requests per minute per key}
        {--active= : 1 to enable, 0 to disable (consumers are never deleted)}';

    protected $description = 'Change a distribution API consumer (until the admin screens exist)';

    public function handle(): int
    {
        $code = (string) $this->argument('code');
        $consumer = DB::table('consumers')->where('code', $code)->first();
        $changes = [];
        $problems = [];

        if ($consumer === null) {
            $problems[] = "consumer {$code} not found";
        }
        if ($this->option('groups') !== null) {
            $groups = self::groups((string) $this->option('groups'));
            if ($groups === null) {
                $problems[] = 'unknown field group in --groups';
            } else {
                $changes['field_groups'] = json_encode($groups);
            }
        }
        if ($this->option('rate') !== null) {
            if (self::validRate($this->option('rate'))) {
                $changes['rate_limit_per_minute'] = (int) $this->option('rate');
            } else {
                $problems[] = '--rate must be 1 to 10000';
            }
        }
        if ($this->option('active') !== null) {
            if (in_array($this->option('active'), ['0', '1'], true)) {
                $changes['active'] = $this->option('active') === '1';
            } else {
                $problems[] = '--active must be 0 or 1';
            }
        }
        if ($problems === [] && $changes === []) {
            $problems[] = 'nothing to change: give --groups, --rate or --active';
        }

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->getOutput()->getErrorStyle()->writeln("hub:consumer-update: {$problem}");
            }

            return self::FAILURE;
        }

        DB::table('consumers')->where('id', $consumer->id)->update($changes + ['updated_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s')]);
        $this->line("hub:consumer-update: {$code} updated (".implode(', ', array_keys($changes)).').');

        return self::SUCCESS;
    }

    /**
     * Configured group codes from a comma list (identity is implied), or null for an unknown one.
     *
     * @return list<string>|null
     */
    public static function groups(string $list): ?array
    {
        $groups = array_values(array_unique(array_filter(array_map('trim', explode(',', $list)))));
        $known = array_keys(config('api_fields.groups'));
        foreach ($groups as $group) {
            if (! in_array($group, $known, true)) {
                return null;
            }
        }

        return array_values(array_diff($groups, config('api_fields.always')));
    }

    public static function validRate(mixed $rate): bool
    {
        return is_string($rate) && preg_match('/^\d{1,5}$/', $rate) && (int) $rate >= 1 && (int) $rate <= 10000;
    }
}
