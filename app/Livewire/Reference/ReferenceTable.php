<?php

namespace App\Livewire\Reference;

use App\Admin\Audit;
use App\Admin\ReapplyPending;
use App\Livewire\AdminPage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * A reference table (manage-reference): list, create, edit, deactivate /
 * reactivate — never delete. Every create and edit sets reviewed_by = the
 * user's name and reviewed_on = today (Kigali); every change is audited with
 * before / after values and marks "re-apply pending" (served values follow
 * within 5 minutes, outside the web request).
 */
abstract class ReferenceTable extends AdminPage
{
    /** null: no form; 0: new row; otherwise the row being edited. */
    public ?int $editing = null;

    /** @var array<string, string|null> */
    public array $form = [];

    abstract protected function table(): string;

    abstract public function title(): string;

    /**
     * @return array<string, array{label: string, type?: string, options?: list<string>, hint?: string}>
     */
    abstract protected function columns(): array;

    /**
     * @return array<string, mixed> validation rules for form.*
     */
    abstract protected function rules(): array;

    /**
     * @param  array<string, string|null>  $data
     * @return array<string, string|int|null>
     */
    protected function normalise(array $data): array
    {
        return $data;
    }

    protected function ability(): string
    {
        return 'manage-reference';
    }

    public function create(): void
    {
        $this->resetValidation();
        $this->editing = 0;
        $this->form = array_fill_keys(array_keys($this->columns()), '');
    }

    public function edit(int $id): void
    {
        $this->resetValidation();
        $row = DB::table($this->table())->where('id', $id)->first();
        abort_if($row === null, 404);
        $this->editing = $id;
        $this->form = [];
        foreach (array_keys($this->columns()) as $column) {
            $this->form[$column] = $row->{$column} === null ? '' : (string) $row->{$column};
        }
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->form = [];
        $this->resetValidation();
    }

    public function save(): void
    {
        if ($this->editing === null) {
            return;
        }
        $data = [];
        foreach (array_keys($this->columns()) as $column) {
            $value = trim((string) ($this->form[$column] ?? ''));
            $data[$column] = $value === '' ? null : $value;
        }
        $data = $this->normalise($data);
        $this->form = array_map(fn ($v) => $v === null ? '' : (string) $v, $data);
        $this->validate($this->rules());

        $now = CarbonImmutable::now('UTC');
        $values = $data + [
            'reviewed_by' => mb_substr((string) auth()->user()->name, 0, 100),
            'reviewed_on' => $now->setTimezone('Africa/Kigali')->format('Y-m-d'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ];

        if ($this->editing === 0) {
            $id = DB::table($this->table())->insertGetId($values + ['active' => true, 'created_at' => $now->format('Y-m-d H:i:s')]);
            Audit::record('reference.created', ['after' => $this->snapshot($id)], $this->table(), $id);
        } else {
            $id = $this->editing;
            $before = $this->snapshot($id);
            DB::table($this->table())->where('id', $id)->update($values);
            Audit::record('reference.updated', ['before' => $before, 'after' => $this->snapshot($id)], $this->table(), $id);
        }

        ReapplyPending::mark();
        $this->cancel();
        session()->flash('status', 'Saved. Served values follow within 5 minutes (re-apply pending).');
    }

    public function toggle(int $id): void
    {
        $before = $this->snapshot($id);
        abort_if($before === null, 404);
        DB::table($this->table())->where('id', $id)->update([
            'active' => ! $before['active'],
            'updated_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
        ]);
        Audit::record($before['active'] ? 'reference.deactivated' : 'reference.reactivated', ['before' => $before, 'after' => $this->snapshot($id)], $this->table(), $id);
        ReapplyPending::mark();
        session()->flash('status', ($before['active'] ? 'Deactivated' : 'Reactivated').'. Served values follow within 5 minutes (re-apply pending).');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function snapshot(int $id): ?array
    {
        $row = DB::table($this->table())->where('id', $id)->first();
        if ($row === null) {
            return null;
        }
        $row = (array) $row;
        $row['active'] = (bool) $row['active'];
        unset($row['created_at'], $row['updated_at']);

        return $row;
    }

    public function render(): View
    {
        return view('livewire.reference.table', [
            'rows' => DB::table($this->table())->orderByDesc('active')->orderBy(array_key_first($this->columns()))->get(),
            'columns' => $this->columns(),
            'pendingSince' => ReapplyPending::since(),
        ])->title($this->title());
    }
}
