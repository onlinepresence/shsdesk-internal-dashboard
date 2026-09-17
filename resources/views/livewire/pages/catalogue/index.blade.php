<?php

use App\Models\Feature;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?int $editingId = null;

    public ?string $key = null;

    public string $kind = 'module';

    public bool $locked = false;

    public bool $default_on = false;

    public bool $active = true;

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
        $this->dispatch('open-feature-form');
    }

    /**
     * Open a blank form. Key, kind, locked, default, and status are only
     * settable here — never on existing rows.
     */
    public function create(): void
    {
        $this->reset(['editingId', 'key', 'label', 'description', 'base_price', 'renewal_base']);
        $this->kind = 'module';
        $this->locked = false;
        $this->default_on = false;
        $this->active = true;
        $this->resetValidation();
        $this->dispatch('open-feature-form');
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'key', 'kind', 'label', 'description', 'base_price', 'renewal_base']);
        $this->locked = false;
        $this->default_on = false;
        $this->active = true;
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

        if ($this->editingId === null) {
            $validated = $this->validate([
                'key' => ['required', 'string', 'max:255', 'unique:features,key'],
                'label' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'kind' => ['required', Rule::in(['core', 'module'])],
                'locked' => ['boolean'],
                'default_on' => ['boolean'],
                'active' => ['boolean'],
                'base_price' => ['nullable', 'numeric', 'min:0'],
                'renewal_base' => ['nullable', 'numeric', 'min:0'],
            ]);

            $feature = new Feature;

            // forceFill: key is deliberately absent from Fillable (immutable).
            $feature->forceFill([
                'key' => $validated['key'],
                'label' => $validated['label'],
                'description' => $validated['description'],
                'kind' => $validated['kind'],
                'locked' => (bool) ($validated['locked'] ?? false),
                'default_on' => (bool) ($validated['default_on'] ?? false),
                'active' => (bool) ($validated['active'] ?? true),
                'base_price' => $validated['base_price'] !== null ? round((float) $validated['base_price'], 2) : null,
                'renewal_base' => $validated['renewal_base'] !== null ? round((float) $validated['renewal_base'], 2) : null,
            ])->save();

            activity('catalogue')
                ->performedOn($feature)
                ->causedBy(Auth::user())
                ->log('catalogue.created');

            $this->dispatch('close-feature-form');
            $this->cancelEdit();

            return;
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

        $this->dispatch('close-feature-form');
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
            <x-section-title title="Catalogue" subtitle="Offerings served from the database. Keys are immutable.">
                <x-primary-button type="button" wire:click="create">
                    {{ __('New feature') }}
                </x-primary-button>
            </x-section-title>

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

            <div
                x-on:open-feature-form.window="$dispatch('open-modal', 'feature-form')"
                x-on:close-feature-form.window="$dispatch('close-modal', 'feature-form')"
            >
                <x-modal name="feature-form" focusable>
                    <form wire:submit="save" class="p-6">
                        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                            {{ $editingId === null ? __('New feature') : __('Edit feature') }}
                        </h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            {{ $editingId === null ? __('Keys cannot be changed once created.') : __('Keys cannot be changed.') }}
                        </p>
                        <div class="mt-6 flex flex-col gap-4">
                            @if ($editingId === null)
                                <div>
                                    <x-input-label for="key" :value="__('Key')" />
                                    <x-text-input wire:model="key" id="key" class="mt-1 block w-full font-mono" type="text" name="key" required placeholder="module_custom" />
                                    <x-input-error :messages="$errors->get('key')" class="mt-2" />
                                </div>
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <x-input-label for="kind" :value="__('Kind')" />
                                        <x-select wire:model="kind" id="kind" name="kind" required class="mt-1 block w-full">
                                            <option value="core">{{ __('Core') }}</option>
                                            <option value="module">{{ __('Module') }}</option>
                                        </x-select>
                                        <x-input-error :messages="$errors->get('kind')" class="mt-2" />
                                    </div>
                                    <div class="flex items-end gap-4 pb-1">
                                        <label class="inline-flex items-center gap-2">
                                            <input wire:model="locked" type="checkbox" class="rounded border-slate-300 dark:border-white/20 dark:bg-ink text-brand shadow-sm focus:ring-brand dark:focus:ring-accent dark:focus:ring-offset-deep" />
                                            <span class="text-sm text-slate-600 dark:text-slate-300">{{ __('Locked') }}</span>
                                        </label>
                                        <label class="inline-flex items-center gap-2">
                                            <input wire:model="default_on" type="checkbox" class="rounded border-slate-300 dark:border-white/20 dark:bg-ink text-brand shadow-sm focus:ring-brand dark:focus:ring-accent dark:focus:ring-offset-deep" />
                                            <span class="text-sm text-slate-600 dark:text-slate-300">{{ __('Default on') }}</span>
                                        </label>
                                        <label class="inline-flex items-center gap-2">
                                            <input wire:model="active" type="checkbox" class="rounded border-slate-300 dark:border-white/20 dark:bg-ink text-brand shadow-sm focus:ring-brand dark:focus:ring-accent dark:focus:ring-offset-deep" />
                                            <span class="text-sm text-slate-600 dark:text-slate-300">{{ __('Active') }}</span>
                                        </label>
                                    </div>
                                </div>
                            @else
                                <p class="font-mono text-sm text-slate-500 dark:text-slate-400">{{ $this->features->firstWhere('id', $editingId)?->key }}</p>
                            @endif
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
                        </div>
                        <div class="mt-6 flex justify-end gap-2">
                            <x-tertiary-button type="button" wire:click="cancelEdit" x-on:click="$dispatch('close')">
                                {{ __('Cancel') }}
                            </x-tertiary-button>
                            <x-primary-button wire:loading.attr="disabled" wire:target="save">
                                <span wire:loading.remove wire:target="save">{{ $editingId === null ? __('Create feature') : __('Save changes') }}</span>
                                <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                            </x-primary-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </div>
    </div>
</div>
