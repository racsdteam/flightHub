<?php

namespace App\Livewire\Consumers;

use App\Admin\Audit;
use App\Api\ApiKeys;
use App\Livewire\AdminPage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Throwable;

/**
 * One consumer (manage-consumers): name, field groups (each with its fields),
 * rate limit, notes; keys: issue (shown ONCE, never in the session, the
 * snapshot or the audit), revoke.
 */
#[Title('Consumer')]
class ConsumerPage extends AdminPage
{
    public string $code;

    public string $name = '';

    /** @var list<string> */
    public array $groups = [];

    public string $rate = '120';

    public string $notes = '';

    public string $label = '';

    public string $expires = '';

    /** The issued key: rendered in this response only (not a public property, so never persisted). */
    protected ?string $issuedKey = null;

    protected ?string $issuedPrefix = null;

    protected function ability(): string
    {
        return 'manage-consumers';
    }

    private function consumer(): object
    {
        $consumer = DB::table('consumers')->where('code', $this->code)->first();
        abort_if($consumer === null, 404);

        return $consumer;
    }

    public function mount(string $code): void
    {
        $this->code = $code;
        $consumer = $this->consumer();
        $this->name = $consumer->name;
        $this->groups = json_decode($consumer->field_groups, true) ?: [];
        $this->rate = (string) $consumer->rate_limit_per_minute;
        $this->notes = (string) $consumer->notes;
    }

    public function saveSettings(): void
    {
        $known = array_diff(array_keys(config('api_fields.groups')), config('api_fields.always'));
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'groups' => ['array'],
            'groups.*' => ['in:'.implode(',', $known)],
            'rate' => ['required', 'integer', 'min:1', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $consumer = $this->consumer();
        $before = ['name' => $consumer->name, 'field_groups' => json_decode($consumer->field_groups, true), 'rate_limit_per_minute' => (int) $consumer->rate_limit_per_minute, 'notes' => $consumer->notes];
        $groups = array_values(array_intersect($known, $this->groups));
        $after = ['name' => trim($this->name), 'field_groups' => $groups, 'rate_limit_per_minute' => (int) $this->rate, 'notes' => trim($this->notes) === '' ? null : trim($this->notes)];

        DB::table('consumers')->where('id', $consumer->id)->update([
            'name' => $after['name'], 'field_groups' => json_encode($groups), 'rate_limit_per_minute' => $after['rate_limit_per_minute'],
            'notes' => $after['notes'], 'updated_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
        ]);
        Audit::record('consumer.updated', ['code' => $consumer->code, 'before' => $before, 'after' => $after], 'consumer', $consumer->id);
        session()->flash('status', 'Consumer saved.');
    }

    public function issueKey(): void
    {
        $this->validate(['label' => ['required', 'string', 'max:100'], 'expires' => ['nullable', 'date', 'after:today']]);
        $consumer = $this->consumer();
        try {
            $expires = $this->expires === '' ? null : CarbonImmutable::parse($this->expires, 'Africa/Kigali')->endOfDay()->utc();
        } catch (Throwable) {
            $this->addError('expires', 'Not a date.');

            return;
        }

        $issued = ApiKeys::issue((int) $consumer->id, trim($this->label), $expires, (string) auth()->user()->email);
        Audit::record('key.issued', ['consumer' => $consumer->code, 'prefix' => $issued['prefix'], 'label' => trim($this->label), 'expires_at' => $expires?->format('Y-m-d\TH:i:s\Z')], 'api_key', $issued['id']);

        $this->issuedKey = $issued['key'];
        $this->issuedPrefix = $issued['prefix'];
        $this->label = '';
        $this->expires = '';
    }

    public function revoke(int $keyId): void
    {
        $consumer = $this->consumer();
        $key = DB::table('api_keys')->where(['id' => $keyId, 'consumer_id' => $consumer->id])->whereNull('revoked_at')->first();
        abort_if($key === null, 404);
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        DB::table('api_keys')->where('id', $keyId)->update(['revoked_at' => $now, 'revoked_by' => (string) auth()->user()->email, 'updated_at' => $now]);
        Audit::record('key.revoked', ['consumer' => $consumer->code, 'prefix' => $key->prefix], 'api_key', $keyId);
        session()->flash('status', "Key {$key->prefix} revoked: refused from the next call.");
    }

    public function render(): View
    {
        $consumer = $this->consumer();
        // Display only: usage summary from api_calls (Kigali days).
        $today = CarbonImmutable::now('Africa/Kigali')->startOfDay();
        $calls = fn () => DB::table('api_calls')->where('consumer_id', $consumer->id);

        return view('livewire.consumers.page', [
            'usage' => [
                'today' => $calls()->where('at', '>=', $today->utc()->format('Y-m-d H:i:s'))->count(),
                'week' => $calls()->where('at', '>=', $today->subDays(6)->utc()->format('Y-m-d H:i:s'))->count(),
                'last' => $calls()->orderByDesc('id')->first(['at', 'status', 'path']),
            ],
            'consumer' => $consumer,
            'groupFields' => array_diff_key(config('api_fields.groups'), array_flip(config('api_fields.always'))),
            'identity' => config('api_fields.groups.identity'),
            'keys' => DB::table('api_keys')->where('consumer_id', $consumer->id)->orderByDesc('id')->get(),
            'issuedKey' => $this->issuedKey,
            'issuedPrefix' => $this->issuedPrefix,
        ])->title('Consumer '.$consumer->code);
    }
}
