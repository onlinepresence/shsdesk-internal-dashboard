<?php

use App\Models\Deployment;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
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

    public function updatedDeployment(): void
    {
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
}; ?>

<div class="py-12">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="mb-6 flex flex-col gap-6">
            <x-section-title title="Invoices" subtitle="Generated proforma bills, newest first." />

            <x-card>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div class="w-full sm:max-w-xs">
                        <x-input-label for="deployment" :value="__('Deployment')" />
                        <x-select wire:model.live="deployment" id="deployment" name="deployment" class="mt-1 block w-full">
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
                            <x-table.heading><span class="sr-only">View</span></x-table.heading>
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
                                        @if ($invoice->deployment)
                                            <x-button-link :href="route('licences.invoices.show', [$invoice->deployment->uuid, $invoice->id])" variant="tertiary">
                                                {{ __('View') }}
                                            </x-button-link>
                                        @endif
                                    </x-table.cell>
                                </x-table.row>
                            @endforeach
                        </x-table.body>
                    @else
                        <x-table.empty>
                            <x-empty-state title="No invoices yet" message="Generate one from a deployment licence page.">
                                <a href="{{ route('licences.index') }}" wire:navigate class="text-sm font-medium text-brand underline hover:text-deep dark:text-slate-200 dark:hover:text-white">
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
        </div>
    </div>
</div>
