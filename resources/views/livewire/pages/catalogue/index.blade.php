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
                ->log('catalogue.created');

            $this->dispatch('toast', message: __('Feature created.'));

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

        $this->dispatch('toast', message: __('Feature updated.'));

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

        $this->dispatch('toast', message: $feature->active ? __('Feature activated.') : __('Feature deactivated.'));
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

        $this->dispatch('toast', message: __('Pricing globals saved.'));
    }
}; ?>

<div class="py-12">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="mb-6 flex flex-col gap-6" x-data="{ tab: 'features' }">
            <x-section-title title="Catalogue" subtitle="Offerings served from the database. Keys are immutable." />

            <div class="flex flex-wrap items-end justify-between gap-4 border-b border-slate-200 dark:border-white/10">
                <div class="-mb-px flex gap-6" role="tablist" aria-label="Catalogue sections">
                    <button type="button" role="tab" id="tab-features" aria-controls="panel-features" :aria-selected="tab === 'features'" @click="tab = 'features'" :class="tab === 'features' ? 'border-brand text-brand dark:border-accent dark:text-white' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700 dark:text-slate-400 dark:hover:border-white/20 dark:hover:text-slate-200'" class="rounded-t-md border-b-2 px-1 pb-2 text-sm font-medium transition duration-150 ease-in-out focus:outline-none focus:ring-2 focus:ring-brand dark:focus:ring-accent">{{ __('Features') }}</button>
                    <button type="button" role="tab" id="tab-globals" aria-controls="panel-globals" :aria-selected="tab === 'globals'" @click="tab = 'globals'" :class="tab === 'globals' ? 'border-brand text-brand dark:border-accent dark:text-white' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700 dark:text-slate-400 dark:hover:border-white/20 dark:hover:text-slate-200'" class="rounded-t-md border-b-2 px-1 pb-2 text-sm font-medium transition duration-150 ease-in-out focus:outline-none focus:ring-2 focus:ring-brand dark:focus:ring-accent">{{ __('Pricing globals') }}</button>
                </div>
                <div class="pb-2" x-show="tab === 'features'">
                    <x-primary-button type="button" wire:click="create" x-data="" x-on:click="$dispatch('open-modal', 'feature-form')">
                        {{ __('New feature') }}
                    </x-primary-button>
                </div>
            </div>

            <div x-show="tab === 'features'" role="tabpanel" id="panel-features" aria-labelledby="tab-features">
            <x-card>
                <x-table loading-except="create, edit, cancelEdit, saveGlobals, addBand, removeBand">
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
            </div>

            <div x-show="tab === 'globals'" role="tabpanel" id="panel-globals" aria-labelledby="tab-globals" style="display: none;">
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
                    <div x-data="{ bands: $wire.entangle('bands') }">
                        <x-input-label :value="__('Student bands')" />
                        <div class="mt-1 flex flex-col gap-2" wire:ignore>
                            <template x-for="(band, index) in bands" :key="index">
                                <div class="grid grid-cols-2 items-start gap-2 sm:grid-cols-[1fr_1fr_1fr_2fr_auto]">
                                    <div>
                                        <x-text-input x-model="band.min" class="block w-full" type="number" min="0" placeholder="Min" aria-label="Band minimum students" />
                                    </div>
                                    <div>
                                        <x-text-input x-model="band.max" class="block w-full" type="number" min="0" placeholder="Max (blank = open)" aria-label="Band maximum students" />
                                    </div>
                                    <div>
                                        <x-text-input x-model="band.multiplier" class="block w-full" type="number" min="0" step="0.01" placeholder="×" aria-label="Band multiplier" />
                                    </div>
                                    <div>
                                        <x-text-input x-model="band.label" class="block w-full" type="text" placeholder="Label" aria-label="Band label" />
                                    </div>
                                    <div>
                                        <x-tertiary-button type="button" @click="bands.splice(index, 1)" aria-label="Remove band">
                                            {{ __('Remove') }}
                                        </x-tertiary-button>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <div class="mt-2 flex items-center gap-2">
                            <x-tertiary-button type="button" @click="bands.push({min: null, max: null, multiplier: null, label: null})">
                                {{ __('Add band') }}
                            </x-tertiary-button>
                            <x-input-error :messages="$errors->get('bands')" class="mt-2" />
                        </div>
                        @php
                            $bandMessages = collect($errors->getMessages())
                                ->filter(fn ($messages, $key) => str_starts_with((string) $key, 'bands.'))
                                ->flatten()
                                ->map(fn ($message) => preg_replace_callback('/\bbands\.(\d+)\.(\w+)/', fn ($matches) => 'Row '.((int) $matches[1] + 1).' '.str_replace('_', ' ', $matches[2]), $message));
                        @endphp
                        @if ($bandMessages->isNotEmpty())
                            <x-alert tone="danger" title="Fix the band rows before saving.">
                                <ul class="list-disc space-y-1 pl-5">
                                    @foreach ($bandMessages as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            </x-alert>
                        @endif
                    </div>
                    <div class="flex justify-end">
                        <x-primary-button wire:loading.attr="disabled" wire:target="saveGlobals">
                            <span wire:loading.remove wire:target="saveGlobals">{{ __('Save globals') }}</span>
                            <span wire:loading wire:target="saveGlobals">{{ __('Saving…') }}</span>
                        </x-primary-button>
                    </div>
                </div>
            </x-card>
            </div>

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

            <div
                x-data="{
                    toasts: [],
                    tones: {
                        success: 'bg-green-500',
                        danger: 'bg-red-500',
                        info: 'bg-brand',
                    },
                    pushToast(message, tone) {
                        const id = Date.now() + Math.random();
                        this.toasts.push({ id, message, tone });
                        setTimeout(() => { this.toasts = this.toasts.filter((toast) => toast.id !== id); }, 4500);
                    },
                }"
                x-on:toast.window="pushToast($event.detail.message, $event.detail.tone || 'success')"
                class="pointer-events-none fixed bottom-4 right-4 z-[60] flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-2"
                aria-live="polite"
            >
                <template x-for="toast in toasts" :key="toast.id">
                    <div class="pointer-events-auto flex items-start gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3 shadow-lg dark:border-white/10 dark:bg-deep" role="status">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="tones[toast.tone] || tones.success" aria-hidden="true"></span>
                        <p class="flex-1 text-sm font-medium text-slate-700 dark:text-slate-200" x-text="toast.message"></p>
                        <button type="button" @click="toasts = toasts.filter((item) => item.id !== toast.id)" aria-label="Dismiss notification" class="shrink-0 rounded-md p-1 text-slate-400 hover:text-slate-600 focus:outline-none focus:ring-2 focus:ring-brand dark:text-slate-500 dark:hover:text-slate-300 dark:focus:ring-accent">×</button>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>
