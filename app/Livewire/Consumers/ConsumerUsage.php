<?php

namespace App\Livewire\Consumers;

use App\Livewire\AdminPage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * api_calls of one consumer (manage-consumers): last N days (default 7),
 * status and path filters; counts per day and status.
 */
#[Title('API usage')]
class ConsumerUsage extends AdminPage
{
    use WithPagination;

    public string $code;

    #[Url]
    public int $days = 7;

    #[Url]
    public string $status = '';

    #[Url]
    public string $path = '';

    protected function ability(): string
    {
        return 'manage-consumers';
    }

    public function mount(string $code): void
    {
        abort_unless(DB::table('consumers')->where('code', $code)->exists(), 404);
        $this->code = $code;
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    private function calls(int $consumerId): Builder
    {
        $days = max(1, min(365, $this->days));
        $from = CarbonImmutable::now('Africa/Kigali')->startOfDay()->subDays($days - 1)->utc();

        return DB::table('api_calls')->where('consumer_id', $consumerId)
            ->where('at', '>=', $from->format('Y-m-d H:i:s'))
            ->when(preg_match('/^\d{3}$/', $this->status), fn ($q) => $q->where('status', (int) $this->status))
            ->when(trim($this->path) !== '', fn ($q) => $q->where('path', 'like', '%'.trim($this->path).'%'));
    }

    public function render(): View
    {
        $consumer = DB::table('consumers')->where('code', $this->code)->first();
        abort_if($consumer === null, 404);

        return view('livewire.consumers.usage', [
            'consumer' => $consumer,
            'perDay' => $this->calls($consumer->id)
                ->selectRaw("DATE(CONVERT_TZ(at, '+00:00', '+02:00')) AS day, status, COUNT(*) AS n")
                ->groupBy('day', 'status')->orderByDesc('day')->orderBy('status')->get()->groupBy('day'),
            'statuses' => $this->calls($consumer->id)->selectRaw('status, COUNT(*) AS n')->groupBy('status')->orderBy('status')->pluck('n', 'status')->all(),
            'calls' => $this->calls($consumer->id)->orderByDesc('id')->paginate(50),
        ])->title('API usage · '.$consumer->code);
    }
}
