<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConsumerCreateCommand extends Command
{
    protected $signature = 'hub:consumer-create
        {code : Short code, e.g. kia-pulse}
        {name : Display name}
        {--groups= : Comma-separated field groups (identity is always given)}
        {--rate=120 : Requests per minute per key}
        {--notes= : Free text}
        {--by= : Who creates it (free text until admin accounts exist)}';

    protected $description = 'Create a distribution API consumer (until the admin screens exist)';

    public function handle(): int
    {
        $code = (string) $this->argument('code');
        $groups = ConsumerUpdateCommand::groups((string) $this->option('groups'));
        $by = trim((string) $this->option('by'));

        $problems = array_filter([
            preg_match('/^[a-z0-9][a-z0-9-]{1,31}$/', $code) ? null : 'code must be 2-32 characters: a-z, 0-9, -',
            DB::table('consumers')->where('code', $code)->exists() ? "consumer {$code} already exists" : null,
            $groups === null ? 'unknown field group in --groups' : null,
            ConsumerUpdateCommand::validRate($this->option('rate')) ? null : '--rate must be 1 to 10000',
            $by !== '' && mb_strlen($by) <= 100 ? null : '--by is required (at most 100 characters)',
        ]);
        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->getOutput()->getErrorStyle()->writeln("hub:consumer-create: {$problem}");
            }

            return self::FAILURE;
        }

        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        DB::table('consumers')->insert([
            'code' => $code,
            'name' => mb_substr((string) $this->argument('name'), 0, 100),
            'active' => true,
            'field_groups' => json_encode($groups),
            'rate_limit_per_minute' => (int) $this->option('rate'),
            'notes' => $this->option('notes') === null ? null : mb_substr((string) $this->option('notes'), 0, 500),
            'created_by' => $by,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->line("hub:consumer-create: {$code} created with groups ".implode(', ', ['identity', ...$groups]).'.');

        return self::SUCCESS;
    }
}
