<?php

namespace App\Livewire\Consumers;

use App\Admin\Audit;
use App\Livewire\AdminPage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;

/**
 * Distribution API consumers (manage-consumers): list, create, disable / enable.
 */
#[Title('Consumers')]
class ConsumerList extends AdminPage
{
    public bool $creating = false;

    public string $code = '';

    public string $name = '';

    protected function ability(): string
    {
        return 'manage-consumers';
    }

    public function startCreate(): void
    {
        $this->creating = true;
    }

    public function cancelCreate(): void
    {
        $this->creating = false;
        $this->reset('code', 'name');
        $this->resetValidation();
    }

    public function create(): void
    {
        $this->validate([
            'code' => ['required', 'regex:/^[a-z0-9][a-z0-9-]{1,31}$/', Rule::unique('consumers', 'code')],
            'name' => ['required', 'string', 'max:100'],
        ]);
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        $id = DB::table('consumers')->insertGetId([
            'code' => $this->code, 'name' => trim($this->name), 'active' => true, 'field_groups' => '[]',
            'rate_limit_per_minute' => 120, 'created_by' => (string) auth()->user()->email,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        Audit::record('consumer.created', ['after' => ['code' => $this->code, 'name' => trim($this->name), 'field_groups' => [], 'rate_limit_per_minute' => 120]], 'consumer', $id);
        $this->redirectRoute('consumers.show', $this->code);
    }

    public function toggle(int $id): void
    {
        $consumer = DB::table('consumers')->where('id', $id)->first();
        abort_if($consumer === null, 404);
        DB::table('consumers')->where('id', $id)->update(['active' => ! $consumer->active, 'updated_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s')]);
        Audit::record($consumer->active ? 'consumer.disabled' : 'consumer.enabled', ['code' => $consumer->code, 'active' => ['from' => (bool) $consumer->active, 'to' => ! $consumer->active]], 'consumer', $id);
    }

    public function render(): View
    {
        return view('livewire.consumers.list', [
            'consumers' => DB::table('consumers as c')->orderBy('c.code')->select('c.*')
                ->selectSub(fn ($q) => $q->from('api_keys')->whereColumn('consumer_id', 'c.id')->whereNull('revoked_at')->selectRaw('COUNT(*)'), 'live_keys')
                ->selectSub(fn ($q) => $q->from('api_keys')->whereColumn('consumer_id', 'c.id')->selectRaw('MAX(last_used_at)'), 'last_used_at')
                ->get(),
        ]);
    }
}
