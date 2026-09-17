<?php

use App\Models\Feature;
use App\Models\Setting;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;
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

    public ?string $currency = null;

    public $core_base = null;

    public $discount_rate = null;

    /** @var list<array{min: mixed, max: mixed, multiplier: mixed, label: ?string}> */
    public array $bands = [];

    public function mount(): void
    {
        $this->currency = Setting::get(Setting::CURRENCY, 'GHS');
        $this->core_base = Setting::get(Setting::CORE_BASE_ANNUAL);
        $this->discount_rate = Setting::get(Setting::ALL_MODULES_DISCOUNT_RATE);
        $this->bands = array_map(fn (array $band): array => [
            'min' => $band['min'] ?? null,
            'max' => $band['max'] ?? null,
            'multiplier' => $band['multiplier'] ?? null,
            'label' => $band['label'] ?? null,
        ], Setting::studentBands());
    }

    #[Computed]
    public function features(): LengthAwarePaginator
    {
        return Feature::orderBy('id')->paginate(10);
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
                ->withProperties(['old' => null, 'new' => $feature->fresh()->only(['key', 'label', 'description', 'kind', 'locked', 'default_on', 'active', 'base_price', 'renewal_base'])])
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

    public function addBand(): void
    {
        $this->bands[] = ['min' => null, 'max' => null, 'multiplier' => null, 'label' => null];
    }

    public function removeBand(int $index): void
    {
        unset($this->bands[$index]);

        $this->bands = array_values($this->bands);
    }

    /**
     * Persist pricing globals. Everything the annual preview needs beyond
     * per-feature prices lives here, editable without touching config.
     */
    public function saveGlobals(): void
    {
        foreach ($this->bands as $index => $band) {
            if (($band['max'] ?? null) === '') {
                $this->bands[$index]['max'] = null;
            }
        }

        $validated = $this->validate([
            'currency' => ['required', 'string', 'max:10'],
            'core_base' => ['required', 'numeric', 'min:0'],
            'discount_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'bands' => ['required', 'array', 'min:1'],
            'bands.*.min' => ['required', 'integer', 'min:0'],
            'bands.*.max' => ['nullable', 'integer', 'min:0'],
            'bands.*.multiplier' => ['required', 'numeric', 'min:0'],
            'bands.*.label' => ['required', 'string', 'max:255'],
        ]);

        foreach ($validated['bands'] as $index => $band) {
            if ($band['max'] !== null && $band['max'] < $band['min']) {
                $this->addError("bands.{$index}.max", __('The band maximum must be at least its minimum.'));

                return;
            }
        }

        $before = [
            'currency' => Setting::get(Setting::CURRENCY),
            'core_base_annual' => Setting::get(Setting::CORE_BASE_ANNUAL),
            'all_modules_discount_rate' => Setting::get(Setting::ALL_MODULES_DISCOUNT_RATE),
            'student_bands' => Setting::studentBands(),
        ];

        Setting::set(Setting::CURRENCY, $validated['currency']);
        Setting::set(Setting::CORE_BASE_ANNUAL, (string) round((float) $validated['core_base'], 2));
        Setting::set(Setting::ALL_MODULES_DISCOUNT_RATE, (string) $validated['discount_rate']);
        Setting::set(Setting::STUDENT_BANDS, json_encode(array_map(fn (array $band): array => [
            'min' => (int) $band['min'],
            'max' => $band['max'] === null ? null : (int) $band['max'],
            'multiplier' => (float) $band['multiplier'],
            'label' => $band['label'],
        ], $validated['bands'])));

        $after = [
            'currency' => Setting::get(Setting::CURRENCY),
            'core_base_annual' => Setting::get(Setting::CORE_BASE_ANNUAL),
            'all_modules_discount_rate' => Setting::get(Setting::ALL_MODULES_DISCOUNT_RATE),
            'student_bands' => Setting::studentBands(),
        ];

        activity('catalogue')
            ->causedBy(Auth::user())
            ->withProperties(['before' => $before, 'after' => $after])
            ->log('settings.updated');
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

                <div class="mt-4">
                    {{ $this->features->links() }}
                </div>
            </x-card>

            <x-card>
                <x-slot name="title">Pricing globals</x-slot>
                <div class="flex flex-col gap-4">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <x-input-label for="currency" :value="__('Currency')" />
                            <x-text-input wire:model="currency" id="currency" class="mt-1 block w-full" type="text" name="currency" required maxlength="10" />
                            <x-input-error :messages="$errors->get('currency')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="core_base" :value="__('Core base annual')" />
                            <x-text-input wire:model="core_base" id="core_base" class="mt-1 block w-full" type="number" min="0" step="0.01" name="core_base" required />
                            <x-input-error :messages="$errors->get('core_base')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="discount_rate" :value="__('All-modules discount rate (0–1)')" />
                            <x-text-input wire:model="discount_rate" id="discount_rate" class="mt-1 block w-full" type="number" min="0" max="1" step="0.01" name="discount_rate" required />
                            <x-input-error :messages="$errors->get('discount_rate')" class="mt-2" />
                        </div>
                    </div>
                    <div>
                        <x-input-label :value="__('Student bands')" />
                        <div class="mt-1 flex flex-col gap-2">
                            @foreach ($bands as $index => $band)
                                <div class="grid grid-cols-2 items-start gap-2 sm:grid-cols-[1fr_1fr_1fr_2fr_auto]">
                                    <div>
                                        <x-text-input wire:model="bands.{{ $index }}.min" class="block w-full" type="number" min="0" placeholder="Min" aria-label="Band minimum students" />
                                        <x-input-error :messages="$errors->get('bands.'.$index.'.min')" class="mt-2" />
                                    </div>
                                    <div>
                                        <x-text-input wire:model="bands.{{ $index }}.max" class="block w-full" type="number" min="0" placeholder="Max (blank = open)" aria-label="Band maximum students" />
                                        <x-input-error :messages="$errors->get('bands.'.$index.'.max')" class="mt-2" />
                                    </div>
                                    <div>
                                        <x-text-input wire:model="bands.{{ $index }}.multiplier" class="block w-full" type="number" min="0" step="0.01" placeholder="×" aria-label="Band multiplier" />
                                        <x-input-error :messages="$errors->get('bands.'.$index.'.multiplier')" class="mt-2" />
                                    </div>
                                    <div>
                                        <x-text-input wire:model="bands.{{ $index }}.label" class="block w-full" type="text" placeholder="Label" aria-label="Band label" />
                                        <x-input-error :messages="$errors->get('bands.'.$index.'.label')" class="mt-2" />
                                    </div>
                                    <div>
                                        <x-tertiary-button type="button" wire:click="removeBand({{ $index }})" aria-label="Remove band">
                                            {{ __('Remove') }}
                                        </x-tertiary-button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-2 flex items-center gap-2">
                            <x-tertiary-button type="button" wire:click="addBand">
                                {{ __('Add band') }}
                            </x-tertiary-button>
                            <x-input-error :messages="$errors->get('bands')" class="mt-2" />
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <x-primary-button wire:loading.attr="disabled" wire:target="saveGlobals">
                            <span wire:loading.remove wire:target="saveGlobals">{{ __('Save globals') }}</span>
                            <span wire:loading wire:target="saveGlobals">{{ __('Saving…') }}</span>
                        </x-primary-button>
                    </div>
                </div>
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
                                <textarea wire:model="description" id="description" name="description" rows="3" class="mt-1 block w-full border-slate-300 dark:border-white/15 dark:bg-ink dark:text-slate-100 focus:border-brand dark:focus:border-accent focus:ring-brand dark:focus:ring-accent rounded-md shadow-sm"></textarea>
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
