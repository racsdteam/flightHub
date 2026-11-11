<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class KeyRevokeCommand extends Command
{
    protected $signature = 'hub:key-revoke
        {prefix : The key prefix (fdh_xxxxxxxx)}
        {--by= : Who revokes it (free text until admin accounts exist)}';

    protected $description = 'Revoke an API key (takes effect on the next call)';

    public function handle(): int
    {
        $prefix = (string) $this->argument('prefix');
        $by = trim((string) $this->option('by'));
        $key = DB::table('api_keys')->where('prefix', $prefix)->first();

        $problem = match (true) {
            $key === null => 'key not found',
            $key->revoked_at !== null => 'key already revoked',
            $by === '' || mb_strlen($by) > 100 => '--by is required (at most 100 characters)',
            default => null,
        };
        if ($problem !== null) {
            $this->getOutput()->getErrorStyle()->writeln("hub:key-revoke: {$problem}");

            return self::FAILURE;
        }

        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        DB::table('api_keys')->where('id', $key->id)->update(['revoked_at' => $now, 'revoked_by' => $by, 'updated_at' => $now]);
        $this->line("hub:key-revoke: {$prefix} revoked.");

        return self::SUCCESS;
    }
}
