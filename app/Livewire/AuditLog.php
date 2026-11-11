<?php

namespace App\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Throwable;

/**
 * admin_audit viewer (view-audit): filters user, action, Kigali date range; read-only.
 */
#[Title('Audit log')]
class AuditLog extends AdminPage
{
    use WithPagination;

    #[Url]
    public string $actor = '';

    #[Url]
    public string $action = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    protected function ability(): string
    {
        return 'view-audit';
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    private static function day(string $date, bool $end): ?string
    {
        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Africa/Kigali');

            return ($end ? $day->addDay() : $day)->utc()->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    public function render(): View
    {
        $from = $this->from === '' ? null : self::day($this->from, false);
        $to = $this->to === '' ? null : self::day($this->to, true);

        return view('livewire.audit-log', [
            'actions' => DB::table('admin_audit')->distinct()->orderBy('action')->pluck('action')->all(),
            'actors' => DB::table('admin_audit')->distinct()->orderBy('actor')->pluck('actor')->all(),
            'rows' => DB::table('admin_audit')
                ->when($this->actor !== '', fn ($q) => $q->where('actor', $this->actor))
                ->when($this->action !== '', fn ($q) => $q->where('action', $this->action))
                ->when($from, fn ($q) => $q->where('at', '>=', $from))
                ->when($to, fn ($q) => $q->where('at', '<', $to))
                ->orderByDesc('id')->paginate(50),
        ]);
    }
}
