<?php

use App\Models\Deployment;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    #[Url]
    public ?string $deployment = null;

    public ?int $deletingId = null;

    public ?int $editingId = null;

    public ?string $due_at = null;

    public ?string $next_payment_at = null;

    public function mount(): void
    {
        $this->authorize('ops.access');
    }

    public function updatedDeployment(): void
    {
        $this->authorize('ops.access');

        $this->resetPage();
    }

    #[Computed]
    public function deployments(): EloquentCollection
    {
        return Deployment::orderBy('school_name')->get(['id', 'uuid', 'school_name']);
    }

    #[Computed]
    public function scopedDeployment(): ?Deployment
    {
        if ($this->deployment === null || $this->deployment === '') {
            return null;
        }

        return Deployment::where('uuid', $this->deployment)->first();
    }

    #[Computed]
    public function invoices(): LengthAwarePaginator
    {
        return Invoice::with('deployment')
            ->when(
                $this->deployment,
                fn ($query) => $query->whereHas('deployment', fn ($query) => $query->where('uuid', $this->deployment)),
            )
            ->latest()
            ->paginate(10);
    }

    /**
     * Load a pending invoice's dates into the edit form. The modal
     * itself opens client-side, so this only fills the fields.
     */
    public function beginEdit(int $id): void
    {
        $this->authorize('ops.access');

        $invoice = Invoice::findOrFail($id);

        $this->editingId = $invoice->id;
        $this->due_at = $invoice->due_at?->format('Y-m-d');
        $this->next_payment_at = $invoice->next_payment_at?->format('Y-m-d');
        $this->resetValidation();
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'due_at', 'next_payment_at']);
        $this->resetValidation();
        $this->dispatch('close-invoice-edit');
    }

    /**
     * Save edited payment dates. Priced lines stay snapshotted —
     * only the schedule moves, and only while pending.
     */
    public function updateInvoice(): void
    {
        $this->authorize('ops.access');

        $invoice = Invoice::findOrFail($this->editingId);

        if (! $invoice->isPending()) {
            $this->addError('invoice', __('Only pending invoices can be edited.'));

            return;
        }

        if ($this->next_payment_at === '') {
            $this->next_payment_at = null;
        }

        $validated = $this->validate([
            'due_at' => ['required', 'date'],
            'next_payment_at' => ['nullable', 'date'],
        ]);

        $before = $invoice->only(['due_at', 'next_payment_at']);

        $invoice->update([
            'due_at' => $validated['due_at'],
            'next_payment_at' => $validated['next_payment_at'],
        ]);

        activity('invoices')
            ->performedOn($invoice)
            ->causedBy(Auth::user())
            ->withProperties(['before' => $before, 'after' => $invoice->fresh()->only(['due_at', 'next_payment_at'])])
            ->log('invoice.updated');

        $this->reset(['editingId', 'due_at', 'next_payment_at']);
        $this->dispatch('close-invoice-edit');

        session()->flash('status', __('Invoice updated.'));
    }

    public function cancelDelete(): void
    {
        $this->reset(['deletingId']);
        $this->resetValidation();
        $this->dispatch('close-invoice-delete');
    }

    /**
     * Delete the staged pending invoice and log it.
     */
    public function delete(): void
    {
        $this->authorize('ops.access');

        $invoice = Invoice::findOrFail($this->deletingId);

        if (! $invoice->isPending()) {
            $this->addError('invoice', __('Only pending invoices can be deleted.'));

            return;
        }

        $invoice->delete();

        activity('invoices')
            ->causedBy(Auth::user())
            ->withProperties(['invoice_id' => $invoice->id, 'invoice_no' => $invoice->invoice_no])
            ->log('invoice.deleted');

        $this->reset(['deletingId']);
        $this->dispatch('close-invoice-delete');

        session()->flash('status', __('Invoice deleted.'));
    }
}; ?>

<div class="py-12">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="flex flex-col gap-6 mb-6">
            <x-section-title title="Invoices" subtitle="Generated proforma bills, newest first." />

            <x-alert flash="status" tone="success" />

            <x-card>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div class="w-full sm:max-w-xs">
                        <x-input-label for="deployment" :value="__('Deployment')" />
                        <x-select wire:model.live="deployment" id="deployment" name="deployment" class="block w-full mt-1">
                            <option value="">{{ __('All deployments') }}</option>
                            @foreach ($this->deployments as $item)
                                <option value="{{ $item->uuid }}">{{ $item->school_name }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    @if ($this->scopedDeployment)
                        <p class="text-sm text-slate-500 dark:text-slate-400">
                            Showing bills for <span class="font-medium text-slate-700 dark:text-slate-200">{{ $this->scopedDeployment->school_name }}</span>.
                        </p>
                    @endif
                </div>
            </x-card>

            <x-card>
                <x-table>
                    <x-table.head>
                        <x-table.row :hover="false">
                            <x-table.heading>Invoice</x-table.heading>
                            <x-table.heading>School</x-table.heading>
                            <x-table.heading>Upfront</x-table.heading>
                            <x-table.heading>Renewal</x-table.heading>
                            <x-table.heading>Due</x-table.heading>
                            <x-table.heading>Status</x-table.heading>
                            <x-table.heading><span class="sr-only">Actions</span></x-table.heading>
                        </x-table.row>
                    </x-table.head>
                    @if ($this->invoices->isNotEmpty())
                        <x-table.body>
                            @foreach ($this->invoices as $invoice)
                                <x-table.row>
                                    <x-table.cell>
                                        <div class="font-medium text-slate-900 dark:text-white">{{ $invoice->invoice_no ?? 'Draft' }}</div>
                                        <div class="text-xs text-slate-400 dark:text-slate-500">{{ $invoice->created_at?->format('M d, Y') }}</div>
                                    </x-table.cell>
                                    <x-table.cell>{{ $invoice->deployment?->school_name ?? '—' }}</x-table.cell>
                                    <x-table.cell>
                                        {{ isset($invoice->pricing['upfront_total']) ? ($invoice->pricing['currency'] ?? 'GHS').' '.number_format($invoice->pricing['upfront_total'], 2) : '—' }}
                                    </x-table.cell>
                                    <x-table.cell>
                                        {{ isset($invoice->pricing['renew_total']) ? ($invoice->pricing['currency'] ?? 'GHS').' '.number_format($invoice->pricing['renew_total'], 2) : '—' }}
                                    </x-table.cell>
                                    <x-table.cell>
                                        {{ $invoice->due_at?->format('M d, Y') ?? '—' }}
                                    </x-table.cell>
                                    <x-table.cell>
                                        <x-badge :tone="$invoice->isPending() ? 'warn' : 'success'">{{ ucfirst($invoice->status) }}</x-badge>
                                    </x-table.cell>
                                    <x-table.cell>
                                        <span class="flex items-center gap-1">
                                            @if ($invoice->deployment)
                                                <x-icon-button :href="route('licences.invoices.show', [$invoice->deployment->uuid, $invoice->id])" target="_blank" rel="noopener" label="Print invoice">
                                                    <x-lucide-printer class="w-4 h-4" aria-hidden="true" />
                                                </x-icon-button>
                                            @endif
                                            @if ($invoice->isPending())
                                                <x-icon-button tone="brand" x-data="" x-on:click.prevent="$dispatch('open-modal', 'invoice-edit'); $wire.beginEdit({{ $invoice->id }})" label="Edit invoice">
                                                    <x-lucide-pencil class="w-4 h-4" aria-hidden="true" />
                                                </x-icon-button>
                                                <x-icon-button tone="danger" x-data="" x-on:click.prevent="$dispatch('open-modal', 'invoice-delete'); $wire.set('deletingId', {{ $invoice->id }})" label="Delete invoice">
                                                    <x-lucide-trash-2 class="w-4 h-4" aria-hidden="true" />
                                                </x-icon-button>
                                            @endif
                                        </span>
                                    </x-table.cell>
                                </x-table.row>
                            @endforeach
                        </x-table.body>
                    @else
                        <x-table.empty>
                            <x-empty-state title="No invoices yet" message="Generate one from a deployment licence page.">
                                <a href="{{ route('licences.index') }}" wire:navigate class="text-sm font-medium underline text-brand hover:text-deep dark:text-slate-200 dark:hover:text-white">
                                    {{ __('Go to licences') }}
                                </a>
                            </x-empty-state>
                        </x-table.empty>
                    @endif
                </x-table>

                <div class="mt-4">
                    {{ $this->invoices->links() }}
                </div>
            </x-card>

            <div x-on:open-invoice-delete.window="$dispatch('open-modal', 'invoice-delete')" x-on:close-invoice-delete.window="$dispatch('close-modal', 'invoice-delete')">
                <x-modal name="invoice-delete" focusable>
                    <form wire:submit="delete" class="p-6">
                        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                            {{ __('Delete this invoice?') }}
                        </h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            {{ __('Only pending invoices can be deleted. This cannot be undone.') }}
                        </p>
                        <x-input-error :messages="$errors->get('invoice')" class="mt-2" />
                        <div class="flex justify-end mt-6">
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
            </div>

            <div x-on:close-invoice-edit.window="$dispatch('close-modal', 'invoice-edit')">
                <x-modal name="invoice-edit" focusable>
                    <form wire:submit="updateInvoice" class="p-6">
                        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                            {{ __('Edit invoice dates') }}
                        </h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            {{ __('Billed lines stay as issued — only the payment schedule moves, and only while pending.') }}
                        </p>
                        <div class="grid grid-cols-1 gap-4 mt-4 sm:grid-cols-2">
                            <div>
                                <x-input-label for="due_at" :value="__('Invoice due date')" />
                                <x-text-input wire:model="due_at" id="due_at" class="block w-full mt-1" type="date" name="due_at" required />
                                <x-input-error :messages="$errors->get('due_at')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="next_payment_at" :value="__('Next payment date')" />
                                <x-text-input wire:model="next_payment_at" id="next_payment_at" class="block w-full mt-1" type="date" name="next_payment_at" />
                                <x-input-error :messages="$errors->get('next_payment_at')" class="mt-2" />
                            </div>
                        </div>
                        <x-input-error :messages="$errors->get('invoice')" class="mt-2" />
                        <div class="flex justify-end mt-6">
                            <x-secondary-button type="button" wire:click="cancelEdit">
                                {{ __('Cancel') }}
                            </x-secondary-button>
                            <x-primary-button class="ms-3" wire:loading.attr="disabled" wire:target="updateInvoice">
                                <span wire:loading.remove wire:target="updateInvoice">{{ __('Save changes') }}</span>
                                <span wire:loading wire:target="updateInvoice">{{ __('Saving…') }}</span>
                            </x-primary-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </div>
    </div>
</div>
