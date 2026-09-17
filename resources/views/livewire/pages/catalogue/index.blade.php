<?php

use App\Models\Feature;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?int $editingId = null;

    public string $label = '';

    public ?string $description = null;

    public $base_price = null;

    public $renewal_base = null;

    #[Computed]
    public function features(): Collection
    {
        return Feature::orderBy('id')->get();
    }

    public function kindTone(string $kind): string
    {
        return $kind === 'core' ? 'active' : 'muted';
    }

    /**
     * Load a row for editing. Key, kind, locked, and default flags are
     * read-only by design — only labels, descriptions, and prices change.
     */
    public function edit(int $id): void
    {
        $feature = Feature::findOrFail($id);

        $this->editingId = $feature->id;
        $this->label = $feature->label;
        $this->description = $feature->description;
        $this->base_price = $feature->base_price;
        $this->renewal_base = $feature->renewal_base;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'label', 'description', 'base_price', 'renewal_base']);
        $this->resetValidation();
    }

    public function save(): void
    {
        foreach (['description'] as $field) {
            if ($this->{$field} === '') {
                $this->{$field} = null;
            }
        }

        foreach (['base_price', 'renewal_base'] as $field) {
            if ($this->{$field} === '') {
                $this->{$field} = null;
            }
        }

        $validated = $this->validate([
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'base_price' => ['nullable', 'numeric', 'min:0'],
            'renewal_base' => ['nullable', 'numeric', 'min:0'],
        ]);

        $feature = Feature::findOrFail($this->editingId);
        $before = $feature->only(['label', 'description', 'base_price', 'renewal_base']);

        $feature->update([
            'label' => $validated['label'],
            'description' => $validated['description'],
            'base_price' => $validated['base_price'] !== null ? round((float) $validated['base_price'], 2) : null,
            'renewal_base' => $validated['renewal_base'] !== null ? round((float) $validated['renewal_base'], 2) : null,
        ]);

        activity('catalogue')
            ->performedOn($feature)
            ->causedBy(Auth::user())
            ->withProperties(['before' => $before, 'after' => $feature->fresh()->only(['label', 'description', 'base_price', 'renewal_base'])])
            ->log('catalogue.updated');

        $this->cancelEdit();
    }

    /**
     * Withdraw or restore an offering. Withdrawn keys stop being granted
     * and stop being answered on heartbeats.
     */
    public function toggleActive(int $id): void
    {
        $feature = Feature::findOrFail($id);

        $feature->update(['active' => ! $feature->active]);

        activity('catalogue')
            ->performedOn($feature)
            ->causedBy(Auth::user())
            ->withProperties(['active' => $feature->active])
            ->log('catalogue.toggled');
    }
}; ?>

<div class="py-12">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="mb-6 flex flex-col gap-6">
            <x-section-title title="Catalogue" subtitle="Offerings served from the database. Keys are immutable." />

            <x-card>
                <x-table>
                    <x-table.head>
                        <x-table.row :hover="false">
                            <x-table.heading>Feature</x-table.heading>
                            <x-table.heading>Kind</x-table.heading>
                            <x-table.heading>Annual</x-table.heading>
                            <x-table.heading>Renewal</x-table.heading>
                            <x-table.heading>Status</x-table.heading>
                            <x-table.heading><span class="sr-only">Actions</span></x-table.heading>
                        </x-table.row>
                    </x-table.head>
                    <x-table.body>
                        @foreach ($this->features as $feature)
                            <x-table.row>
                                <x-table.cell>
                                    <div class="font-medium text-slate-900 dark:text-white">{{ $feature->label }}</div>
                                    <div class="font-mono text-xs text-slate-400 dark:text-slate-500">{{ $feature->key }}</div>
                                </x-table.cell>
                                <x-table.cell>
                                    <span class="flex items-center gap-1">
                                        <x-badge :tone="$this->kindTone($feature->kind)">{{ ucfirst($feature->kind) }}</x-badge>
                                        @if ($feature->locked)
                                            <x-badge tone="muted">Locked</x-badge>
                                        @endif
                                    </span>
                                </x-table.cell>
                                <x-table.cell>{{ $feature->base_price !== null ? 'GHS '.number_format($feature->base_price, 2) : '—' }}</x-table.cell>
                                <x-table.cell>{{ $feature->renewal_base !== null ? 'GHS '.number_format($feature->renewal_base, 2) : '—' }}</x-table.cell>
                                <x-table.cell>
                                    <x-badge :tone="$feature->active ? 'success' : 'muted'">{{ $feature->active ? 'Active' : 'Inactive' }}</x-badge>
                                </x-table.cell>
                                <x-table.cell>
                                    <span class="flex items-center gap-2">
                                        <x-tertiary-button type="button" wire:click="edit({{ $feature->id }})">
                                            {{ __('Edit') }}
                                        </x-tertiary-button>
                                        <button type="button" wire:click="toggleActive({{ $feature->id }})" class="text-sm font-medium text-brand underline hover:text-deep dark:text-slate-200 dark:hover:text-white">
                                            {{ $feature->active ? __('Deactivate') : __('Activate') }}
                                        </button>
                                    </span>
                                </x-table.cell>
                            </x-table.row>
                        @endforeach
                    </x-table.body>
                </x-table>
            </x-card>

            @if ($editingId !== null)
                <x-card>
                    <x-slot name="title">Edit feature</x-slot>
                    <form wire:submit="save" class="flex flex-col gap-4">
                        <p class="font-mono text-sm text-slate-500 dark:text-slate-400">{{ $this->features->firstWhere('id', $editingId)?->key }}</p>
                        <div>
                            <x-input-label for="label" :value="__('Label')" />
                            <x-text-input wire:model="label" id="label" class="mt-1 block w-full" type="text" name="label" required />
                            <x-input-error :messages="$errors->get('label')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="description" :value="__('Description')" />
                            <x-text-input wire:model="description" id="description" class="mt-1 block w-full" type="text" name="description" />
                            <x-input-error :messages="$errors->get('description')" class="mt-2" />
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <x-input-label for="base_price" :value="__('Base price (GHS)')" />
                                <x-text-input wire:model="base_price" id="base_price" class="mt-1 block w-full" type="number" min="0" step="0.01" name="base_price" />
                                <x-input-error :messages="$errors->get('base_price')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="renewal_base" :value="__('Renewal base (GHS)')" />
                                <x-text-input wire:model="renewal_base" id="renewal_base" class="mt-1 block w-full" type="number" min="0" step="0.01" name="renewal_base" />
                                <x-input-error :messages="$errors->get('renewal_base')" class="mt-2" />
                            </div>
                        </div>
                        <div class="flex items-center justify-end gap-2">
                            <x-tertiary-button type="button" wire:click="cancelEdit">
                                {{ __('Cancel') }}
                            </x-tertiary-button>
                            <x-primary-button wire:loading.attr="disabled" wire:target="save">
                                <span wire:loading.remove wire:target="save">{{ __('Save changes') }}</span>
                                <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                            </x-primary-button>
                        </div>
                    </form>
                </x-card>
            @endif
        </div>
    </div>
</div>
