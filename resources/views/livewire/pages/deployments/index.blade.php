<?php

use App\Models\Deployment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = 'all';

    /**
     * Registry totals for the stat row.
     *
     * @return array{total: int, active: int, stale: int, behind: int}
     */
    #[Computed]
    public function stats(): array
    {
        return [
            'total' => Deployment::count(),
            'active' => Deployment::active()->count(),
            'stale' => Deployment::stale()->count(),
            'behind' => $this->versionsBehindCount(),
        ];
    }

    #[Computed]
    public function deployments(): LengthAwarePaginator
    {
        return Deployment::query()
            ->when($this->search !== '', function ($query): void {
                $term = '%'.$this->search.'%';
                $query->where(function ($query) use ($term): void {
                    $query->where('school_name', 'like', $term)
                        ->orWhere('url', 'like', $term)
                        ->orWhere('uuid', 'like', $term)
                        ->orWhere('product', 'like', $term);
                });
            })
            ->when(in_array($this->status, ['active', 'stale', 'revoked'], true), function ($query): void {
                $query->{$this->status}();
            })
            ->latest()
            ->paginate(10);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function statusTone(string $status): string
    {
        return match ($status) {
            'active' => 'active',
            'stale' => 'warn',
            'revoked' => 'danger',
            default => 'muted',
        };
    }

    /**
     * Distinct reported versions excluding the newest one.
     */
    protected function versionsBehindCount(): int
    {
        $versions = Deployment::query()->whereNotNull('app_version')->distinct()->pluck('app_version')->all();

        if (count($versions) < 2) {
            return 0;
        }

        usort($versions, fn (string $a, string $b): int => version_compare($b, $a));

        return count($versions) - 1;
    }
}; ?>

<div class="py-12">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="mb-6 flex flex-col gap-6">
            <x-section-title title="Deployments" subtitle="School product instances reporting heartbeats.">
                <a href="{{ route('deployments.create') }}" wire:navigate class="inline-flex items-center rounded-md border border-transparent bg-brand px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-deep focus:bg-deep focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2 active:bg-ink dark:bg-brand dark:hover:bg-accent dark:focus:bg-accent dark:focus:ring-accent dark:focus:ring-offset-deep dark:active:bg-accent">
                    {{ __('Register') }}
                </a>
            </x-section-title>

            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <x-stat label="Total" value="{{ $this->stats['total'] }}" />
                <x-stat label="Active" value="{{ $this->stats['active'] }}" />
                <x-stat label="Stale 48h+" value="{{ $this->stats['stale'] }}" />
                <x-stat label="Versions behind" value="{{ $this->stats['behind'] }}" />
            </div>

            <x-card>
                <div class="mb-4 flex flex-col gap-2 sm:flex-row">
                    <x-text-input wire:model.live.debounce.500ms="search" type="search" placeholder="Search school, URL, UUID, product..." class="block w-full sm:max-w-xs" aria-label="Search deployments" />
                    <select wire:model.live="status" aria-label="Filter by status" class="block w-full rounded-md border-slate-300 shadow-sm focus:border-brand focus:ring-brand dark:border-white/15 dark:bg-ink dark:text-slate-100 dark:focus:border-accent dark:focus:ring-accent sm:w-auto">
                        <option value="all">All statuses</option>
                        <option value="active">Active</option>
                        <option value="stale">Stale</option>
                        <option value="revoked">Revoked</option>
                    </select>
                </div>

                <x-table>
                    <x-slot name="head">
                        <th>School</th>
                        <th>Product</th>
                        <th>Version</th>
                        <th>Status</th>
                        <th>Last seen</th>
                    </x-slot>
                    <x-slot name="body">
                        @foreach ($this->deployments as $deployment)
                            <tr>
                                <td>
                                    <a href="{{ route('deployments.show', $deployment) }}" wire:navigate class="font-medium text-brand hover:text-deep dark:text-slate-100 dark:hover:text-white">
                                        {{ $deployment->school_name }}
                                    </a>
                                    <div class="font-mono text-xs text-slate-400 dark:text-slate-500">{{ $deployment->uuid }}</div>
                                </td>
                                <td>{{ $deployment->product }}</td>
                                <td>{{ $deployment->app_version ?? '—' }}</td>
                                <td><x-badge :tone="$this->statusTone($deployment->status)">{{ ucfirst($deployment->status) }}</x-badge></td>
                                <td>{{ $deployment->last_seen_at?->diffForHumans() ?? 'Never' }}</td>
                            </tr>
                        @endforeach
                    </x-slot>
                    <x-slot name="empty">
                        <x-empty-state title="No deployments found" message="Register the first school product instance to start receiving heartbeats.">
                            <a href="{{ route('deployments.create') }}" wire:navigate class="text-sm font-medium text-brand underline hover:text-deep dark:text-slate-200 dark:hover:text-white">
                                {{ __('Register deployment') }}
                            </a>
                        </x-empty-state>
                    </x-slot>
                </x-table>

                <div class="mt-4">
                    {{ $this->deployments->links() }}
                </div>
            </x-card>
        </div>
    </div>
</div>
