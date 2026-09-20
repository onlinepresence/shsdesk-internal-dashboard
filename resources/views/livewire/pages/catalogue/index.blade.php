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

    public $bundle_rate = null;

    public $bundle_threshold = null;

    public $founding_rate = null;

    /** @var list<array{key: string, label: string, core_upfront: mixed, core_renewal: mixed, multiplier: mixed}> */
    public array $core_rows = [];

    public $hosting_self = null;

    public $hosting_managed = null;

    public $hosting_none = null;

    public $config_fee = null;

    public $migration_fee = null;

    public $training_admin = null;

    public $training_teacher = null;

    public $training_onsite = null;

    public ?string $doc_title = null;

    public ?string $company = null;

    public ?string $department = null;

    public ?string $invoice_email = null;

    public ?string $invoice_phone = null;

    public ?string $invoice_location = null;

    public $due_days = null;

    public function mount(): void
    {
        $this->currency = Setting::get(Setting::CURRENCY, 'GHS');
        $this->bundle_rate = Setting::get(Setting::BUNDLE_DISCOUNT_RATE);
        $this->bundle_threshold = Setting::get(Setting::BUNDLE_THRESHOLD);
        $this->founding_rate = Setting::get(Setting::FOUNDING_DISCOUNT_RATE);
        $this->core_rows = array_values(array_filter(
            array_map(fn (array $band): ?array => empty($band['custom']) ? [
                'key' => $band['key'],
                'label' => $band['label'],
                'core_upfront' => $band['core_upfront'],
                'core_renewal' => $band['core_renewal'],
                'multiplier' => $band['multiplier'],
            ] : null, Setting::corePricing())
        ));
        $this->hosting_self = Setting::get(Setting::HOSTING_SELF_HOSTED_FEE);
        $this->hosting_managed = Setting::get(Setting::HOSTING_MANAGED_FEE);
        $this->hosting_none = Setting::get(Setting::HOSTING_NONE_FEE);
        $this->config_fee = Setting::get(Setting::CONFIG_SETUP_FEE);
        $this->migration_fee = Setting::get(Setting::MIGRATION_FEE);
        $this->training_admin = Setting::get(Setting::TRAINING_ADMIN_RATE);
        $this->training_teacher = Setting::get(Setting::TRAINING_TEACHER_RATE);
        $this->training_onsite = Setting::get(Setting::TRAINING_ONSITE_RATE);
        $this->doc_title = Setting::get(Setting::INVOICE_DOC_TITLE, 'Proforma Invoice');
        $this->company = Setting::get(Setting::INVOICE_COMPANY, 'Matme Inc.');
        $this->department = Setting::get(Setting::INVOICE_DEPARTMENT);
        $this->invoice_email = Setting::get(Setting::INVOICE_EMAIL);
        $this->invoice_phone = Setting::get(Setting::INVOICE_PHONE);
        $this->invoice_location = Setting::get(Setting::INVOICE_LOCATION);
        $this->due_days = Setting::get(Setting::INVOICE_DUE_DAYS, '30');
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
     * Persist live pricing globals. Everything the quote preview needs
     * beyond per-feature prices lives here, editable without touching
     * config. Band ranges stay fixed; only figures change.
     */
    public function saveGlobals(): void
    {
        $validated = $this->validate([
            'currency' => ['required', 'string', 'max:10'],
            'bundle_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'bundle_threshold' => ['required', 'integer', 'min:1'],
            'founding_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'core_rows' => ['required', 'array', 'size:4'],
            'core_rows.*.key' => ['required', 'string'],
            'core_rows.*.core_upfront' => ['required', 'numeric', 'min:0'],
            'core_rows.*.core_renewal' => ['required', 'numeric', 'min:0'],
            'core_rows.*.multiplier' => ['required', 'numeric', 'min:0'],
            'hosting_self' => ['required', 'numeric', 'min:0'],
            'hosting_managed' => ['required', 'numeric', 'min:0'],
            'hosting_none' => ['required', 'numeric', 'min:0'],
            'config_fee' => ['required', 'numeric', 'min:0'],
            'migration_fee' => ['required', 'numeric', 'min:0'],
            'training_admin' => ['required', 'numeric', 'min:0'],
            'training_teacher' => ['required', 'numeric', 'min:0'],
            'training_onsite' => ['required', 'numeric', 'min:0'],
        ]);

        $before = $this->globalsSnapshot();

        Setting::set(Setting::CURRENCY, $validated['currency']);
        Setting::set(Setting::BUNDLE_DISCOUNT_RATE, (string) $validated['bundle_rate']);
        Setting::set(Setting::BUNDLE_THRESHOLD, (string) $validated['bundle_threshold']);
        Setting::set(Setting::FOUNDING_DISCOUNT_RATE, (string) $validated['founding_rate']);
        Setting::set(Setting::CORE_PRICING, json_encode($this->mergeCoreRows($validated['core_rows'])));
        Setting::set(Setting::HOSTING_SELF_HOSTED_FEE, (string) round((float) $validated['hosting_self'], 2));
        Setting::set(Setting::HOSTING_MANAGED_FEE, (string) round((float) $validated['hosting_managed'], 2));
        Setting::set(Setting::HOSTING_NONE_FEE, (string) round((float) $validated['hosting_none'], 2));
        Setting::set(Setting::CONFIG_SETUP_FEE, (string) round((float) $validated['config_fee'], 2));
        Setting::set(Setting::MIGRATION_FEE, (string) round((float) $validated['migration_fee'], 2));
        Setting::set(Setting::TRAINING_ADMIN_RATE, (string) round((float) $validated['training_admin'], 2));
        Setting::set(Setting::TRAINING_TEACHER_RATE, (string) round((float) $validated['training_teacher'], 2));
        Setting::set(Setting::TRAINING_ONSITE_RATE, (string) round((float) $validated['training_onsite'], 2));

        activity('catalogue')
            ->causedBy(Auth::user())
            ->withProperties(['before' => $before, 'after' => $this->globalsSnapshot()])
            ->log('settings.updated');

        $this->dispatch('toast', message: __('Pricing globals saved.'));
    }

    /**
     * Persist invoice document settings. Everything the proforma
     * invoice needs beyond the quote itself lives here, editable
     * without touching code. Per-invoice due dates default from
     * due_days but stay overridable at generation time.
     */
    public function saveInvoiceSettings(): void
    {
        foreach (['department', 'invoice_location'] as $field) {
            if ($this->{$field} === '') {
                $this->{$field} = null;
            }
        }

        $validated = $this->validate([
            'doc_title' => ['required', 'string', 'max:100'],
            'company' => ['required', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'invoice_email' => ['required', 'email', 'max:255'],
            'invoice_phone' => ['required', 'string', 'max:50'],
            'invoice_location' => ['nullable', 'string', 'max:255'],
            'due_days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $before = $this->invoiceSnapshot();

        Setting::set(Setting::INVOICE_DOC_TITLE, $validated['doc_title']);
        Setting::set(Setting::INVOICE_COMPANY, $validated['company']);
        Setting::set(Setting::INVOICE_DEPARTMENT, $validated['department']);
        Setting::set(Setting::INVOICE_EMAIL, $validated['invoice_email']);
        Setting::set(Setting::INVOICE_PHONE, $validated['invoice_phone']);
        Setting::set(Setting::INVOICE_LOCATION, $validated['invoice_location']);
        Setting::set(Setting::INVOICE_DUE_DAYS, (string) $validated['due_days']);

        activity('catalogue')
            ->causedBy(Auth::user())
            ->withProperties(['before' => $before, 'after' => $this->invoiceSnapshot()])
            ->log('invoice-settings.updated');

        $this->dispatch('toast', message: __('Invoice settings saved.'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function globalsSnapshot(): array
    {
        return [
            'currency' => Setting::get(Setting::CURRENCY),
            'bundle_discount_rate' => Setting::get(Setting::BUNDLE_DISCOUNT_RATE),
            'bundle_threshold' => Setting::get(Setting::BUNDLE_THRESHOLD),
            'founding_discount_rate' => Setting::get(Setting::FOUNDING_DISCOUNT_RATE),
            'core_pricing' => Setting::corePricing(),
            'hosting_self_hosted_fee' => Setting::get(Setting::HOSTING_SELF_HOSTED_FEE),
            'hosting_managed_fee' => Setting::get(Setting::HOSTING_MANAGED_FEE),
            'hosting_none_fee' => Setting::get(Setting::HOSTING_NONE_FEE),
            'config_setup_fee' => Setting::get(Setting::CONFIG_SETUP_FEE),
            'migration_fee' => Setting::get(Setting::MIGRATION_FEE),
            'training_admin_rate' => Setting::get(Setting::TRAINING_ADMIN_RATE),
            'training_teacher_rate' => Setting::get(Setting::TRAINING_TEACHER_RATE),
            'training_onsite_rate' => Setting::get(Setting::TRAINING_ONSITE_RATE),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function invoiceSnapshot(): array
    {
        return [
            'doc_title' => Setting::get(Setting::INVOICE_DOC_TITLE),
            'company' => Setting::get(Setting::INVOICE_COMPANY),
            'department' => Setting::get(Setting::INVOICE_DEPARTMENT),
            'email' => Setting::get(Setting::INVOICE_EMAIL),
            'phone' => Setting::get(Setting::INVOICE_PHONE),
            'location' => Setting::get(Setting::INVOICE_LOCATION),
            'due_days' => Setting::get(Setting::INVOICE_DUE_DAYS),
        ];
    }

    /**
     * Merge edited figures back onto the stored bands, preserving keys,
     * labels, ranges, and the custom-quote band untouched.
     *
     * @param  list<array{key: string, core_upfront: mixed, core_renewal: mixed, multiplier: mixed}>  $rows
     */
    protected function mergeCoreRows(array $rows): array
    {
        $stored = Setting::corePricing();
        $edited = collect($rows)->keyBy('key');

        return array_map(fn (array $band): array => $edited->has($band['key']) && empty($band['custom']) ? [
            'key' => $band['key'],
            'label' => $band['label'],
            'min' => $band['min'],
            'max' => $band['max'],
            'core_upfront' => round((float) $edited[$band['key']]['core_upfront'], 2),
            'core_renewal' => round((float) $edited[$band['key']]['core_renewal'], 2),
            'multiplier' => (float) $edited[$band['key']]['multiplier'],
            'custom' => false,
        ] : $band, $stored);
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
                    <button type="button" role="tab" id="tab-invoice" aria-controls="panel-invoice" :aria-selected="tab === 'invoice'" @click="tab = 'invoice'" :class="tab === 'invoice' ? 'border-brand text-brand dark:border-accent dark:text-white' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700 dark:text-slate-400 dark:hover:border-white/20 dark:hover:text-slate-200'" class="rounded-t-md border-b-2 px-1 pb-2 text-sm font-medium transition duration-150 ease-in-out focus:outline-none focus:ring-2 focus:ring-brand dark:focus:ring-accent">{{ __('Invoice settings') }}</button>
                </div>
                <div class="pb-2" x-show="tab === 'features'">
                    <x-primary-button type="button" wire:click="create" x-data="" x-on:click="$dispatch('open-modal', 'feature-form')">
                        {{ __('New feature') }}
                    </x-primary-button>
                </div>
            </div>

            <div x-show="tab === 'features'" role="tabpanel" id="panel-features" aria-labelledby="tab-features">
            <x-card>
                <x-table loading-except="create, edit, cancelEdit, saveGlobals">
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
                                        <x-tertiary-button type="button" wire:click="toggleActive({{ $feature->id }})">
                                            {{ $feature->active ? __('Deactivate') : __('Activate') }}
                                        </x-tertiary-button>
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
            <form wire:submit="saveGlobals" class="flex flex-col gap-6">
                <x-card>
                    <x-slot name="title">Currency &amp; discounts</x-slot>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <x-input-label for="currency" :value="__('Currency')" />
                            <x-text-input wire:model="currency" id="currency" class="mt-1 block w-full" type="text" name="currency" required maxlength="10" />
                            <x-input-error :messages="$errors->get('currency')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="founding_rate" :value="__('Founding discount rate (0–1)')" />
                            <x-text-input wire:model="founding_rate" id="founding_rate" class="mt-1 block w-full" type="number" min="0" max="1" step="0.01" name="founding_rate" required />
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Off the core only, upfront and renewal.</p>
                            <x-input-error :messages="$errors->get('founding_rate')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="bundle_rate" :value="__('Bundle discount rate (0–1)')" />
                            <x-text-input wire:model="bundle_rate" id="bundle_rate" class="mt-1 block w-full" type="number" min="0" max="1" step="0.01" name="bundle_rate" required />
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Off modules from the threshold count up.</p>
                            <x-input-error :messages="$errors->get('bundle_rate')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="bundle_threshold" :value="__('Bundle threshold (modules)')" />
                            <x-text-input wire:model="bundle_threshold" id="bundle_threshold" class="mt-1 block w-full" type="number" min="1" name="bundle_threshold" required />
                            <x-input-error :messages="$errors->get('bundle_threshold')" class="mt-2" />
                        </div>
                    </div>
                </x-card>

                <x-card>
                    <x-slot name="title">Core pricing &amp; module multipliers</x-slot>
                    <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">Band ranges are fixed by the FlowEdu quote; only figures change here. The multiplier scales every module price. 3,500+ students is always a custom quote.</p>
                    <div class="flex flex-col gap-4">
                        @foreach ($core_rows as $index => $row)
                            <fieldset class="grid grid-cols-1 gap-4 rounded-lg border border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-4 dark:border-white/10">
                                <legend class="px-1 text-sm font-medium text-slate-700 dark:text-slate-200">{{ $row['label'] }}</legend>
                                <div>
                                    <x-input-label :for="'core_upfront_'.$index" :value="__('Core upfront')" />
                                    <x-text-input wire:model="core_rows.{{ $index }}.core_upfront" :id="'core_upfront_'.$index" class="mt-1 block w-full" type="number" min="0" step="0.01" required />
                                    <x-input-error :messages="$errors->get('core_rows.'.$index.'.core_upfront')" class="mt-2" />
                                </div>
                                <div>
                                    <x-input-label :for="'core_renewal_'.$index" :value="__('Core renewal')" />
                                    <x-text-input wire:model="core_rows.{{ $index }}.core_renewal" :id="'core_renewal_'.$index" class="mt-1 block w-full" type="number" min="0" step="0.01" required />
                                    <x-input-error :messages="$errors->get('core_rows.'.$index.'.core_renewal')" class="mt-2" />
                                </div>
                                <div>
                                    <x-input-label :for="'multiplier_'.$index" :value="__('Module multiplier')" />
                                    <x-text-input wire:model="core_rows.{{ $index }}.multiplier" :id="'multiplier_'.$index" class="mt-1 block w-full" type="number" min="0" step="0.1" required />
                                    <x-input-error :messages="$errors->get('core_rows.'.$index.'.multiplier')" class="mt-2" />
                                </div>
                            </fieldset>
                        @endforeach
                    </div>
                </x-card>

                <x-card>
                    <x-slot name="title">One-time fees &amp; training rates</x-slot>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <x-input-label for="hosting_self" :value="__('Hosting — self-hosted setup')" />
                            <x-text-input wire:model="hosting_self" id="hosting_self" class="mt-1 block w-full" type="number" min="0" step="0.01" name="hosting_self" required />
                            <x-input-error :messages="$errors->get('hosting_self')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="hosting_managed" :value="__('Hosting — managed setup')" />
                            <x-text-input wire:model="hosting_managed" id="hosting_managed" class="mt-1 block w-full" type="number" min="0" step="0.01" name="hosting_managed" required />
                            <x-input-error :messages="$errors->get('hosting_managed')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="hosting_none" :value="__('Hosting — none')" />
                            <x-text-input wire:model="hosting_none" id="hosting_none" class="mt-1 block w-full" type="number" min="0" step="0.01" name="hosting_none" required />
                            <x-input-error :messages="$errors->get('hosting_none')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="config_fee" :value="__('Configuration & data entry')" />
                            <x-text-input wire:model="config_fee" id="config_fee" class="mt-1 block w-full" type="number" min="0" step="0.01" name="config_fee" required />
                            <x-input-error :messages="$errors->get('config_fee')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="migration_fee" :value="__('Legacy data migration')" />
                            <x-text-input wire:model="migration_fee" id="migration_fee" class="mt-1 block w-full" type="number" min="0" step="0.01" name="migration_fee" required />
                            <x-input-error :messages="$errors->get('migration_fee')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="training_admin" :value="__('Remote admin training (each)')" />
                            <x-text-input wire:model="training_admin" id="training_admin" class="mt-1 block w-full" type="number" min="0" step="0.01" name="training_admin" required />
                            <x-input-error :messages="$errors->get('training_admin')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="training_teacher" :value="__('Remote lecturer training (each)')" />
                            <x-text-input wire:model="training_teacher" id="training_teacher" class="mt-1 block w-full" type="number" min="0" step="0.01" name="training_teacher" required />
                            <x-input-error :messages="$errors->get('training_teacher')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="training_onsite" :value="__('On-site training day')" />
                            <x-text-input wire:model="training_onsite" id="training_onsite" class="mt-1 block w-full" type="number" min="0" step="0.01" name="training_onsite" required />
                            <x-input-error :messages="$errors->get('training_onsite')" class="mt-2" />
                        </div>
                    </div>
                    <div class="mt-4 flex justify-end">
                        <x-primary-button wire:loading.attr="disabled" wire:target="saveGlobals">
                            <span wire:loading.remove wire:target="saveGlobals">{{ __('Save globals') }}</span>
                            <span wire:loading wire:target="saveGlobals">{{ __('Saving…') }}</span>
                        </x-primary-button>
                    </div>
                </x-card>
            </form>
            </div>

            <div x-show="tab === 'invoice'" role="tabpanel" id="panel-invoice" aria-labelledby="tab-invoice" style="display: none;">
            <form wire:submit="saveInvoiceSettings" class="flex flex-col gap-6">
                <x-card>
                    <x-slot name="title">Document &amp; payment terms</x-slot>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="doc_title" :value="__('Document title')" />
                            <x-text-input wire:model="doc_title" id="doc_title" class="mt-1 block w-full" type="text" name="doc_title" required maxlength="100" />
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Printed as the invoice heading. Defaults to Proforma Invoice.</p>
                            <x-input-error :messages="$errors->get('doc_title')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="due_days" :value="__('Default due in (days)')" />
                            <x-text-input wire:model="due_days" id="due_days" class="mt-1 block w-full" type="number" min="1" max="365" name="due_days" required />
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Payment window from issue. Overridable per invoice.</p>
                            <x-input-error :messages="$errors->get('due_days')" class="mt-2" />
                        </div>
                    </div>
                </x-card>

                <x-card>
                    <x-slot name="title">Issuing company</x-slot>
                    <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">Printed in the Issued By block. Each invoice keeps a snapshot, so edits here never rewrite issued invoices.</p>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="company" :value="__('Company')" />
                            <x-text-input wire:model="company" id="company" class="mt-1 block w-full" type="text" name="company" required maxlength="255" />
                            <x-input-error :messages="$errors->get('company')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="department" :value="__('Department / team')" />
                            <x-text-input wire:model="department" id="department" class="mt-1 block w-full" type="text" name="department" maxlength="255" />
                            <x-input-error :messages="$errors->get('department')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="invoice_email" :value="__('Email')" />
                            <x-text-input wire:model="invoice_email" id="invoice_email" class="mt-1 block w-full" type="email" name="invoice_email" required maxlength="255" />
                            <x-input-error :messages="$errors->get('invoice_email')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="invoice_phone" :value="__('Phone')" />
                            <x-text-input wire:model="invoice_phone" id="invoice_phone" class="mt-1 block w-full" type="text" name="invoice_phone" required maxlength="50" />
                            <x-input-error :messages="$errors->get('invoice_phone')" class="mt-2" />
                        </div>
                        <div class="sm:col-span-2">
                            <x-input-label for="invoice_location" :value="__('Location')" />
                            <x-text-input wire:model="invoice_location" id="invoice_location" class="mt-1 block w-full" type="text" name="invoice_location" maxlength="255" />
                            <x-input-error :messages="$errors->get('invoice_location')" class="mt-2" />
                        </div>
                    </div>
                    <div class="mt-4 flex justify-end">
                        <x-primary-button wire:loading.attr="disabled" wire:target="saveInvoiceSettings">
                            <span wire:loading.remove wire:target="saveInvoiceSettings">{{ __('Save invoice settings') }}</span>
                            <span wire:loading wire:target="saveInvoiceSettings">{{ __('Saving…') }}</span>
                        </x-primary-button>
                    </div>
                </x-card>
            </form>
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
