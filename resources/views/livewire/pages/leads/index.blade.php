<?php

use App\Models\Lead;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public string $status = 'open';

    public ?int $deletingId = null;

    public ?int $viewingId = null;

    public function mount(): void
    {
        $this->authorize('ops.access');
    }

    public function updatedStatus(): void
    {
        $this->authorize('ops.access');

        $this->resetPage();
    }

    #[Computed]
    public function leads(): LengthAwarePaginator
    {
        return Lead::query()
            ->with('product')
            ->when($this->status === 'open', fn ($query): Builder => $query->whereIn('status', [Lead::STATUS_NEW, Lead::STATUS_REVIEWED]))
            ->when(in_array($this->status, [Lead::STATUS_NEW, Lead::STATUS_REVIEWED, Lead::STATUS_CONVERTED], true), fn ($query): Builder => $query->where('status', $this->status))
            ->latest()
            ->paginate(10);
    }

    public function statusTone(string $status): string
    {
        return match ($status) {
            Lead::STATUS_NEW => 'active',
            Lead::STATUS_REVIEWED => 'warn',
            Lead::STATUS_CONVERTED => 'success',
            default => 'muted',
        };
    }

    /**
     * Lead staged in the detail modal, if any.
     */
    #[Computed]
    public function viewing(): ?Lead
    {
        if ($this->viewingId === null) {
            return null;
        }

        return Lead::with('product')->find($this->viewingId);
    }

    public function closeDetail(): void
    {
        $this->reset(['viewingId']);
        $this->dispatch('close-lead-detail');
    }

    /**
     * Mark a fresh lead as human-reviewed. Spam never converts —
     * delete it instead.
     */
    public function markReviewed(int $id): void
    {
        $this->authorize('ops.access');

        $lead = Lead::findOrFail($id);

        if ($lead->status !== Lead::STATUS_NEW) {
            return;
        }

        $lead->update(['status' => Lead::STATUS_REVIEWED]);

        activity('leads')
            ->performedOn($lead)
            ->causedBy(Auth::user())
            ->log('lead.reviewed');
    }

    /**
     * Stage the quote snapshot for registration and hand over to the
     * registration page. Registration and licence pages pre-fill
     * from it unchanged.
     */
    public function convert(int $id): void
    {
        $this->authorize('ops.access');

        $lead = Lead::findOrFail($id);

        $contact = $lead->contact_name.(($lead->contact_role ?? '') !== '' ? " ({$lead->contact_role})" : '');

        session([
            'lead_prefill' => [
                'lead_id' => $lead->id,
                'school_name' => $lead->school ?? $lead->contact_name,
                'product' => $lead->product->slug,
                'modules' => $lead->modules ?? [],
                'band' => $lead->band,
                'notes' => "Lead: {$contact} — {$lead->contact_email}".(($lead->contact_phone ?? '') !== '' ? ", {$lead->contact_phone}" : ''),
            ],
        ]);

        $this->redirect(route('deployments.create'), navigate: true);
    }

    public function cancelDelete(): void
    {
        $this->reset(['deletingId']);
        $this->resetValidation();
        $this->dispatch('close-lead-delete');
    }

    /**
     * Drop a spam or dead lead from the queue.
     */
    public function delete(): void
    {
        $this->authorize('ops.access');

        $lead = Lead::findOrFail($this->deletingId);

        $lead->delete();

        activity('leads')
            ->causedBy(Auth::user())
            ->withProperties(['lead_id' => $lead->id, 'contact_email' => $lead->contact_email])
            ->log('lead.deleted');

        $this->reset(['deletingId']);
        $this->dispatch('close-lead-delete');

        session()->flash('status', __('Lead deleted.'));
    }
}; ?>

<div class="py-12" x-data="{}" x-on:open-lead-delete.window="$dispatch('open-modal', 'lead-delete')" x-on:close-lead-delete.window="$dispatch('close-modal', 'lead-delete')" x-on:open-lead-detail.window="$dispatch('open-modal', 'lead-detail')" x-on:close-lead-detail.window="$dispatch('close-modal', 'lead-detail')">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="mb-6 flex flex-col gap-6">
            <x-section-title title="Leads" subtitle="Quote requests awaiting human review. Spam stays here — it never becomes access." />

            <x-alert flash="status" tone="success" />

            <x-card>
                <div class="mb-4 flex flex-col gap-2 sm:flex-row">
                    <x-select wire:model.live="status" aria-label="Filter by status" class="block w-full sm:w-auto">
                        <option value="open">Open queue</option>
                        <option value="new">New</option>
                        <option value="reviewed">Reviewed</option>
                        <option value="converted">Converted</option>
                        <option value="all">All</option>
                    </x-select>
                </div>

                <x-table>
                    <x-table.head>
                        <x-table.row :hover="false">
                            <x-table.heading>Received</x-table.heading>
                            <x-table.heading>School</x-table.heading>
                            <x-table.heading>Product</x-table.heading>
                            <x-table.heading>Band</x-table.heading>
                            <x-table.heading>Upfront</x-table.heading>
                            <x-table.heading>Status</x-table.heading>
                            <x-table.heading><span class="sr-only">Actions</span></x-table.heading>
                        </x-table.row>
                    </x-table.head>
                    @if ($this->leads->isNotEmpty())
                        <x-table.body>
                            @foreach ($this->leads as $lead)
                                <x-table.row>
                                    <x-table.cell>{{ $lead->created_at->diffForHumans() }}</x-table.cell>
                                    <x-table.cell>
                                        <div class="font-medium text-slate-900 dark:text-white">{{ $lead->school ?? $lead->contact_name }}</div>
                                        <div class="text-xs text-slate-400 dark:text-slate-500">{{ $lead->contact_email }}</div>
                                    </x-table.cell>
                                    <x-table.cell>{{ $lead->product?->slug ?? '—' }}</x-table.cell>
                                    <x-table.cell>{{ $lead->band }}</x-table.cell>
                                    <x-table.cell>{{ number_format((float) $lead->quote_upfront, 2) }}</x-table.cell>
                                    <x-table.cell>
                                        <x-badge :tone="$this->statusTone($lead->status)">{{ ucfirst($lead->status) }}</x-badge>
                                    </x-table.cell>
                                    <x-table.cell>
                                        <span class="flex items-center gap-1">
                                            <x-icon-button x-data="" x-on:click.prevent="$dispatch('open-modal', 'lead-detail'); $wire.set('viewingId', {{ $lead->id }})" label="View lead">
                                                <x-lucide-eye class="w-4 h-4" aria-hidden="true" />
                                            </x-icon-button>
                                            @if ($lead->status !== Lead::STATUS_CONVERTED)
                                                <x-icon-button tone="brand" wire:click="convert({{ $lead->id }})" label="Convert lead">
                                                    <x-lucide-arrow-right class="w-4 h-4" aria-hidden="true" />
                                                </x-icon-button>
                                            @endif
                                            @if ($lead->status === Lead::STATUS_NEW)
                                                <x-icon-button tone="success" wire:click="markReviewed({{ $lead->id }})" label="Mark reviewed">
                                                    <x-lucide-check class="w-4 h-4" aria-hidden="true" />
                                                </x-icon-button>
                                            @endif
                                            <x-icon-button tone="danger" x-data="" x-on:click.prevent="$dispatch('open-modal', 'lead-delete'); $wire.set('deletingId', {{ $lead->id }})" label="Delete lead">
                                                <x-lucide-trash-2 class="w-4 h-4" aria-hidden="true" />
                                            </x-icon-button>
                                        </span>
                                    </x-table.cell>
                                </x-table.row>
                            @endforeach
                        </x-table.body>
                    @else
                        <x-table.empty>
                            <x-empty-state title="No leads in this view" message="Quote requests from product sites land here for review." />
                        </x-table.empty>
                    @endif
                </x-table>

                <div class="mt-4">
                    {{ $this->leads->links() }}
                </div>
            </x-card>

            <x-modal name="lead-delete" focusable>
                <form wire:submit="delete" class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        {{ __('Delete this lead?') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('The quote request leaves the queue. This cannot be undone.') }}
                    </p>
                    <div class="mt-6 flex justify-end">
                        <x-secondary-button type="button" wire:click="cancelDelete">
                            {{ __('Cancel') }}
                        </x-secondary-button>
                        <x-danger-button class="ms-3" wire:loading.attr="disabled" wire:target="delete">
                            <span wire:loading.remove wire:target="delete">{{ __('Delete') }}</span>
                            <span wire:loading wire:target="delete">{{ __('Deleting…') }}</span>
                        </x-danger-button>
                    </div>
                </form>
            </x-modal>

            <x-modal name="lead-detail" focusable>
                <div class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        {{ __('Lead detail') }}
                    </h2>
                    @if ($this->viewing !== null)
                        <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Contact</dt>
                                <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $this->viewing->contact_name }}</dd>
                                @if (($this->viewing->contact_role ?? '') !== '')
                                    <dd class="text-sm text-slate-500 dark:text-slate-400">{{ $this->viewing->contact_role }}</dd>
                                @endif
                                <dd class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ $this->viewing->contact_email }}</dd>
                                @if (($this->viewing->contact_phone ?? '') !== '')
                                    <dd class="text-sm text-slate-600 dark:text-slate-300">{{ $this->viewing->contact_phone }}</dd>
                                @endif
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">School</dt>
                                <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $this->viewing->school ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Product</dt>
                                <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $this->viewing->product?->slug ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Band</dt>
                                <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $this->viewing->band }}</dd>
                            </div>
                            <div class="sm:col-span-2">
                                <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Modules</dt>
                                <dd class="mt-1 font-mono text-sm text-slate-900 dark:text-white">{{ implode(', ', (array) ($this->viewing->modules ?? [])) ?: '—' }}</dd>
                            </div>
                            <div class="sm:col-span-2">
                                <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Quote</dt>
                                <dd class="mt-1 text-sm text-slate-900 dark:text-white">
                                    Upfront {{ number_format((float) $this->viewing->quote_upfront, 2) }} ·
                                    Renewal {{ number_format((float) $this->viewing->quote_renewal, 2) }}
                                </dd>
                                <ul class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                                    @foreach ((array) ($this->viewing->quote_lines ?? []) as $line)
                                        <li>{{ $line['label'] ?? '—' }} — {{ number_format((float) ($line['amount'] ?? 0), 2) }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </dl>
                    @endif
                    <div class="mt-6 flex justify-end">
                        <x-secondary-button type="button" wire:click="closeDetail">
                            {{ __('Close') }}
                        </x-secondary-button>
                    </div>
                </div>
            </x-modal>
        </div>
    </div>
</div>
