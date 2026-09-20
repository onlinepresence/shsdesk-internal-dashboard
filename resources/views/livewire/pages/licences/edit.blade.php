<?php

use App\Models\Deployment;
use App\Models\Feature;
use App\Models\Invoice;
use App\Models\Licence;
use App\Models\Setting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public Deployment $deployment;

    /** @var list<string> Enabled toggleable core db_column keys. */
    public array $core = [];

    /** @var list<string> Enabled module db_column keys. */
    public array $modules = [];

    public $max_students = null;

    public ?string $student_band = null;

    public ?string $starts_at = null;

    public ?string $expires_at = null;

    public ?string $notes = null;

    public string $hosting_mode = 'self_hosted';

    public bool $config_setup = false;

    public bool $migration = false;

    public $training_admin = 0;

    public $training_teacher = 0;

    public $training_onsite = 0;

    public bool $founding_client = false;

    public ?string $due_at = null;

    public ?string $next_payment_at = null;

    public function mount(Deployment $deployment): void
    {
        $this->deployment = $deployment;

        $current = $deployment->latestLicence;

        if ($current === null) {
            $this->core = $this->toggleableCoreKeys(true);
            $this->modules = $this->moduleKeys(true);
            $this->starts_at = today()->toDateString();
            $this->expires_at = today()->addYear()->toDateString();
            $this->hosting_mode = 'self_hosted';
            $this->config_setup = false;
            $this->migration = false;
            $this->student_band = null;

            $prefill = session()->pull('licence_prefill_'.$deployment->id);

            if (is_array($prefill)) {
                $this->modules = array_values(array_intersect($prefill['modules'] ?? [], $this->moduleKeys(false)));

                if (in_array($prefill['band'] ?? null, $this->bandKeys(), true)) {
                    $this->student_band = $prefill['band'];
                    $this->updatedStudentBand();
                }

                $this->notes = is_string($prefill['notes'] ?? null) && trim($prefill['notes']) !== ''
                    ? trim($prefill['notes'])
                    : null;
            }

            return;
        }

        $this->core = array_keys(array_filter((array) $current->core));
        $this->modules = array_keys(array_filter((array) $current->modules));
        $this->max_students = $current->caps['max_active_students'] ?? null;
        $this->student_band = Licence::bandKeyForCap(
            isset($current->caps['max_active_students']) ? (int) $current->caps['max_active_students'] : null
        );
        $this->starts_at = $current->starts_at?->format('Y-m-d');
        $this->expires_at = $current->expires_at?->format('Y-m-d');
        $this->notes = $current->notes;
        $this->hosting_mode = $current->hosting_mode ?? 'self_hosted';
        $this->config_setup = (bool) $current->config_setup;
        $this->migration = (bool) $current->migration;
        $this->training_admin = $current->training_admin ?? 0;
        $this->training_teacher = $current->training_teacher ?? 0;
        $this->training_onsite = $current->training_onsite ?? 0;
        $this->founding_client = (bool) $current->founding_client;
    }

    /**
     * Read-only upfront/renewal quote for the current form state,
     * mirroring FlowEdu's quote maths.
     */
    #[Computed]
    public function preview(): array
    {
        return Licence::previewFor(
            array_fill_keys($this->modules, true),
            $this->maxStudents(),
            $this->reportedStudents(),
            $this->quoteInput(),
        );
    }

    /**
     * Create the first licence row or update the current one.
     */
    public function save(): void
    {
        $this->normalizeBlanks();
        $validated = $this->validate($this->rules());

        $current = $this->deployment->latestLicence;
        $locked = $current !== null && $this->quoteLocked;

        // Once an invoice exists the priced inputs are read-only: keep
        // carrying the stored terms instead of whatever was posted.
        $moduleKeys = $locked
            ? array_keys(array_filter((array) $current->modules))
            : ($validated['modules'] ?? []);
        $maxStudents = $locked && isset($current->caps['max_active_students'])
            ? (int) $current->caps['max_active_students']
            : $this->maxStudents();
        $quote = $locked
            ? [
                'hosting_mode' => $current->hosting_mode,
                'config_setup' => (bool) $current->config_setup,
                'migration' => (bool) $current->migration,
                'training_admin' => $current->training_admin ?? 0,
                'training_teacher' => $current->training_teacher ?? 0,
                'training_onsite' => $current->training_onsite ?? 0,
                'founding_client' => (bool) $current->founding_client,
            ]
            : $this->quoteInput();

        $payload = [
            'core' => array_fill_keys($validated['core'] ?? [], true),
            'modules' => array_fill_keys($moduleKeys, true),
            'caps' => $maxStudents !== null ? ['max_active_students' => $maxStudents] : null,
            'price_snapshot' => Licence::priceSnapshot(
                array_fill_keys($moduleKeys, true),
                $maxStudents,
                $this->reportedStudents(),
                $quote,
            ),
            'starts_at' => $validated['starts_at'],
            'expires_at' => $validated['expires_at'],
            'notes' => $validated['notes'],
            'hosting_mode' => $quote['hosting_mode'],
            'config_setup' => (bool) ($quote['config_setup'] ?? false),
            'migration' => (bool) ($quote['migration'] ?? false),
            'training_admin' => (int) ($quote['training_admin'] ?? 0),
            'training_teacher' => (int) ($quote['training_teacher'] ?? 0),
            'training_onsite' => (int) ($quote['training_onsite'] ?? 0),
            'founding_client' => (bool) ($quote['founding_client'] ?? false),
        ];

        if ($current === null) {
            $licence = $this->deployment->licences()->create($payload);

            activity('licences')
                ->performedOn($licence)
                ->causedBy(Auth::user())
                ->withProperties(['old' => null, 'new' => $licence->fresh()->toArray()])
                ->log('licence.created');

            session()->flash('status', __('Licence issued.'));
        } else {
            $before = $current->only([
                'core', 'modules', 'caps', 'starts_at', 'expires_at', 'notes',
                'hosting_mode', 'config_setup', 'migration',
                'training_admin', 'training_teacher', 'training_onsite', 'founding_client',
            ]);

            $current->update($payload);

            activity('licences')
                ->performedOn($current)
                ->causedBy(Auth::user())
                ->withProperties(['old' => $before, 'new' => $current->fresh()->only(array_keys($before))])
                ->log('licence.updated');

            session()->flash('status', __('Licence updated.'));
        }

        $this->redirect(route('licences.index'), navigate: true);
    }

    /**
     * Insert a fresh row carrying the current terms forward one year.
     */
    public function renew(): void
    {
        $current = $this->deployment->latestLicence;

        abort_unless($current instanceof Licence, 404);

        $base = $current->expires_at !== null && $current->expires_at->isFuture()
            ? $current->expires_at
            : today();

        $renewed = $this->deployment->licences()->create([
            'core' => $current->core ?? [],
            'modules' => $current->modules ?? [],
            'caps' => $current->caps,
            'price_snapshot' => Licence::priceSnapshot(
                array_fill_keys(array_keys(array_filter((array) $current->modules)), true),
                isset($current->caps['max_active_students']) ? (int) $current->caps['max_active_students'] : null,
                $this->reportedStudents(),
                [
                    'hosting_mode' => $current->hosting_mode,
                    'config_setup' => (bool) $current->config_setup,
                    'migration' => (bool) $current->migration,
                    'training_admin' => $current->training_admin ?? 0,
                    'training_teacher' => $current->training_teacher ?? 0,
                    'training_onsite' => $current->training_onsite ?? 0,
                    'founding_client' => (bool) $current->founding_client,
                ],
            ),
            'notes' => $current->notes,
            'starts_at' => today()->toDateString(),
            'expires_at' => $base->copy()->addYear()->toDateString(),
            'hosting_mode' => $current->hosting_mode,
            'config_setup' => (bool) $current->config_setup,
            'migration' => (bool) $current->migration,
            'training_admin' => $current->training_admin ?? 0,
            'training_teacher' => $current->training_teacher ?? 0,
            'training_onsite' => $current->training_onsite ?? 0,
            'founding_client' => (bool) $current->founding_client,
        ]);

        activity('licences')
            ->performedOn($renewed)
            ->causedBy(Auth::user())
            ->withProperties([
                'old' => ['licence_id' => $current->id, 'expires_at' => $current->expires_at?->toDateString()],
                'new' => ['licence_id' => $renewed->id, 'expires_at' => $renewed->expires_at->toDateString()],
            ])
            ->log('licence.renewed');

        session()->flash('status', __('Licence renewed.'));

        $this->redirect(route('licences.index'), navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'core' => ['array'],
            'core.*' => [Rule::in($this->toggleableCoreKeys(false))],
            'modules' => ['array'],
            'modules.*' => [Rule::in($this->moduleKeys(false))],
            'max_students' => ['nullable', 'integer', 'min:1'],
            'student_band' => ['nullable', 'string', Rule::in($this->bandKeys())],
            'hosting_mode' => ['required', 'string', Rule::in(Licence::HOSTING_MODES)],
            'config_setup' => ['boolean'],
            'migration' => ['boolean'],
            'training_admin' => ['nullable', 'integer', 'min:0'],
            'training_teacher' => ['nullable', 'integer', 'min:0'],
            'training_onsite' => ['nullable', 'integer', 'min:0'],
            'founding_client' => ['boolean'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => [
                'nullable',
                'date',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== null && $this->starts_at !== null && $value < $this->starts_at) {
                        $fail(__('The expiry date must fall on or after the start date.'));
                    }
                },
            ],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * Band preset picked in the form. Fills the cap with the band max
     * (or the band min for the open-ended custom band) unless the
     * current cap already sits inside the band, so typing a cap never
     * gets clobbered by the sync-back below.
     */
    public function updatedStudentBand(): void
    {
        if ($this->student_band === null || $this->student_band === '') {
            $this->student_band = null;
            $this->max_students = null;

            return;
        }

        $current = $this->maxStudents();

        foreach (Setting::corePricing() as $band) {
            if ($band['key'] !== $this->student_band) {
                continue;
            }

            $inBand = $current !== null
                && $current >= $band['min']
                && ($band['max'] === null || $current <= $band['max']);

            if (! $inBand) {
                $this->max_students = $band['max'] ?? $band['min'];
            }

            return;
        }

        $this->student_band = null;
    }

    /**
     * Keep the band preset in sync when the cap is typed by hand.
     */
    public function updatedMaxStudents(): void
    {
        $key = Licence::bandKeyForCap($this->maxStudents());

        if ($key !== $this->student_band) {
            $this->student_band = $key;
        }
    }

    /**
     * @return list<string>
     */
    protected function bandKeys(): array
    {
        return array_column(Setting::corePricing(), 'key');
    }

    /**
     * Newest stored invoice for this deployment, if one was issued.
     */
    #[Computed]
    public function latestInvoice(): ?Invoice
    {
        return $this->deployment->invoices()->latest()->first();
    }

    /**
     * Whether priced inputs are read-only. Once an invoice exists the
     * modules, band/cap, and quote details carry the stored terms.
     */
    #[Computed]
    public function quoteLocked(): bool
    {
        return $this->latestInvoice !== null;
    }

    /**
     * Pending invoice blocking a fresh one, if any. Only one unpaid
     * bill may be open per deployment at a time.
     */
    #[Computed]
    public function pendingInvoice(): ?Invoice
    {
        return $this->deployment->invoices()->where('status', Invoice::STATUS_PENDING)->latest()->first();
    }

    /**
     * Whether the priced form inputs differ from the saved licence.
     * Invoices bill the saved terms, so a dirty quote must be saved
     * before a new invoice can go out. Non-priced fields (core
     * toggles, dates, notes) never block invoicing.
     */
    #[Computed]
    public function quoteDirty(): bool
    {
        $current = $this->deployment->latestLicence;

        if ($current === null) {
            return true;
        }

        $savedModules = array_keys(array_filter((array) $current->modules));
        $formModules = array_values($this->modules);

        sort($savedModules);
        sort($formModules);

        if ($savedModules !== $formModules) {
            return true;
        }

        $savedCap = isset($current->caps['max_active_students']) ? (int) $current->caps['max_active_students'] : null;

        if ($savedCap !== $this->maxStudents()) {
            return true;
        }

        return ($current->hosting_mode ?? 'self_hosted') !== $this->hosting_mode
            || (bool) $current->config_setup !== (bool) $this->config_setup
            || (bool) $current->migration !== (bool) $this->migration
            || (int) ($current->training_admin ?? 0) !== (int) ($this->training_admin ?? 0)
            || (int) ($current->training_teacher ?? 0) !== (int) ($this->training_teacher ?? 0)
            || (int) ($current->training_onsite ?? 0) !== (int) ($this->training_onsite ?? 0)
            || (bool) $current->founding_client !== (bool) $this->founding_client;
    }

    protected function normalizeBlanks(): void
    {
        foreach (['starts_at', 'expires_at', 'notes'] as $field) {
            if ($this->{$field} === '') {
                $this->{$field} = null;
            }
        }

        if ($this->max_students === '') {
            $this->max_students = null;
        }

        foreach (['training_admin', 'training_teacher', 'training_onsite'] as $field) {
            if ($this->{$field} === '' || $this->{$field} === null) {
                $this->{$field} = 0;
            }
        }
    }

    protected function maxStudents(): ?int
    {
        if ($this->max_students === null || $this->max_students === '') {
            return null;
        }

        return (int) $this->max_students;
    }

    /**
     * Quote dimensions for the preview and snapshot.
     *
     * @return array{hosting_mode: ?string, config_setup: bool, migration: bool, training_admin: int, training_teacher: int, training_onsite: int, founding_client: bool}
     */
    protected function quoteInput(): array
    {
        return [
            'hosting_mode' => $this->hosting_mode,
            'config_setup' => (bool) $this->config_setup,
            'migration' => (bool) $this->migration,
            'training_admin' => (int) ($this->training_admin ?? 0),
            'training_teacher' => (int) ($this->training_teacher ?? 0),
            'training_onsite' => (int) ($this->training_onsite ?? 0),
            'founding_client' => (bool) $this->founding_client,
        ];
    }

    /**
     * Form metadata (hosting options, addon fees, training rates) in a
     * single pricing read, so the form never fans out into one query
     * per price label on every keystroke.
     *
     * @return array{hostingOptions: array<string, array{label: string, fee: float}>, configSetupFee: float, migrationFee: float, rates: array{admin: float, teacher: float, onsite: float}}
     */
    #[Computed]
    public function quoteMeta(): array
    {
        return Licence::quoteMeta();
    }

    /**
     * Active licence row eligible for air-gap export, if any.
     */
    #[Computed]
    public function exportableLicence(): ?Licence
    {
        $licence = $this->deployment->latestLicence;

        if ($licence === null || $licence->isExpired()) {
            return null;
        }

        return $licence;
    }

    /**
     * Pretty-signed licence file text for the copy-paste fallback.
     * Null when nothing is exportable or the signing key is missing.
     */
    #[Computed]
    public function exportFileJson(): ?string
    {
        if ($this->exportableLicence === null || empty(config('licence-export.signing_key'))) {
            return null;
        }

        return Licence::exportFileJson($this->exportableLicence);
    }

    /**
     * Open the invoice modal with due dates prefilled: the payment
     * window from invoice settings, overridable here, and the next
     * annual payment a year out.
     */
    public function openInvoiceModal(): void
    {
        $dueDays = max(1, Setting::getInt(Setting::INVOICE_DUE_DAYS, 30));
        $this->due_at = today()->addDays($dueDays)->toDateString();
        $this->next_payment_at = today()->addYear()->toDateString();
        $this->resetValidation();
        $this->dispatch('open-invoice-form');
    }

    /**
     * Store the current (possibly unsaved) quote as a proforma invoice
     * and ask the browser to open the printable FlowEdu-style invoice.
     */
    public function createInvoice(): void
    {
        if ($this->pendingInvoice !== null) {
            $this->addError('invoice', __('Settle or delete pending invoice :no before generating a new one.', ['no' => $this->pendingInvoice->invoice_no]));

            return;
        }

        if ($this->quoteDirty) {
            $this->addError('invoice', __('Save the licence before generating an invoice — invoices bill the saved terms.'));

            return;
        }

        if ($this->next_payment_at === '') {
            $this->next_payment_at = null;
        }

        $validated = $this->validate([
            'due_at' => ['required', 'date'],
            'next_payment_at' => ['nullable', 'date'],
        ]);

        $moduleFlags = array_fill_keys($this->modules, true);

        $invoice = $this->deployment->invoices()->create([
            'licence_id' => $this->deployment->latestLicence?->id,
            'pricing' => Licence::priceSnapshot($moduleFlags, $this->maxStudents(), $this->reportedStudents(), $this->quoteInput()),
            'contact' => [
                'college_name' => $this->deployment->school_name,
                'url' => $this->deployment->url,
            ],
            'due_at' => $validated['due_at'],
            'next_payment_at' => $validated['next_payment_at'],
            'doc_title' => Setting::get(Setting::INVOICE_DOC_TITLE, 'Proforma Invoice'),
            'issuer' => [
                'company' => Setting::get(Setting::INVOICE_COMPANY, 'Matme Inc.'),
                'department' => Setting::get(Setting::INVOICE_DEPARTMENT),
                'email' => Setting::get(Setting::INVOICE_EMAIL),
                'phone' => Setting::get(Setting::INVOICE_PHONE),
                'location' => Setting::get(Setting::INVOICE_LOCATION),
            ],
            'created_by' => Auth::id(),
        ]);

        $invoice->update([
            'invoice_no' => 'FE-'.$invoice->created_at->format('Ymd').'-'.str_pad((string) $invoice->id, 4, '0', STR_PAD_LEFT),
        ]);

        unset($this->latestInvoice, $this->quoteLocked, $this->pendingInvoice);

        $this->dispatch('close-invoice-form');
        $this->dispatch('open-invoice', url: route('licences.invoices.show', [$this->deployment->uuid, $invoice->id]));
    }

    private ?int $reportedCache = null;

    private bool $reportedLoaded = false;

    /**
     * Latest reported student count, if this deployment ever checked in.
     * Memoized per request so preview, save, and renew share one query.
     */
    protected function reportedStudents(): ?int
    {
        if (! $this->reportedLoaded) {
            $this->reportedCache = $this->deployment->heartbeats()->latest()->first()?->students;
            $this->reportedLoaded = true;
        }

        return $this->reportedCache;
    }

    /**
     * Live catalogue split for the form. Only active features are
     * offered; keys granted while active but since withdrawn are
     * dropped on save, matching what heartbeats answer.
     *
     * @return array{locked: \Illuminate\Support\Collection, core: \Illuminate\Support\Collection, modules: \Illuminate\Support\Collection}
     */
    #[Computed]
    public function offerings(): array
    {
        $features = Feature::query()->where('active', true)->orderBy('id')->get();

        return [
            'locked' => $features->where('locked', true)->where('kind', 'core')->values(),
            'core' => $features->where('locked', false)->where('kind', 'core')->values(),
            'modules' => $features->where('kind', 'module')->values(),
        ];
    }

    /**
     * Toggleable core keys, optionally only catalogue defaults.
     *
     * @return list<string>
     */
    protected function toggleableCoreKeys(bool $defaultsOnly): array
    {
        return Feature::query()
            ->where('kind', 'core')
            ->where('locked', false)
            ->where('active', true)
            ->when($defaultsOnly, fn ($query) => $query->where('default_on', true))
            ->orderBy('id')
            ->pluck('key')
            ->all();
    }

    /**
     * Module keys, optionally only catalogue defaults.
     *
     * @return list<string>
     */
    protected function moduleKeys(bool $defaultsOnly): array
    {
        return Feature::query()
            ->where('kind', 'module')
            ->where('active', true)
            ->when($defaultsOnly, fn ($query) => $query->where('default_on', true))
            ->orderBy('id')
            ->pluck('key')
            ->all();
    }
}; ?>

<div class="py-12" x-data="{}" x-on:open-invoice.window="window.open($event.detail.url, '_blank')" x-on:open-invoice-form.window="$dispatch('open-modal', 'invoice-form')" x-on:close-invoice-form.window="$dispatch('close-modal', 'invoice-form')">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="mb-6 flex flex-col gap-6">
            <x-section-title :title="__('Licence — ').$deployment->school_name" subtitle="Terms in force for this deployment.">
                @if ($this->exportFileJson !== null)
                    <x-button-link :href="route('licences.export', $deployment->uuid)" variant="tertiary">
                        {{ __('Export licence file') }}
                    </x-button-link>
                @endif
                <x-button-link :href="route('invoices.index', ['deployment' => $deployment->uuid])" wire:navigate variant="tertiary">
                    {{ __('Invoices') }}
                </x-button-link>
                <x-button-link :href="route('licences.index')" wire:navigate variant="tertiary">
                    {{ __('Back to licences') }}
                </x-button-link>
            </x-section-title>

            <form wire:submit="save">
                <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-5">
                    <div class="flex min-w-0 flex-col gap-6 lg:col-span-3">
                <x-card>
                    <x-slot name="title">Core features</x-slot>
                    <div class="flex flex-col gap-3">
                        <p class="text-sm text-slate-500 dark:text-slate-400">Locked core features are always on and cannot be toggled.</p>
                        @foreach ($this->offerings['locked'] as $feature)
                            <label class="flex cursor-pointer items-center gap-3">
                                <input type="checkbox" disabled checked class="rounded border-slate-300 opacity-60 dark:border-white/20 dark:bg-ink text-brand shadow-sm focus:ring-brand dark:focus:ring-accent dark:focus:ring-offset-deep" aria-label="{{ $feature->label }}" />
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-slate-700 dark:text-slate-200">{{ $feature->label }}</span>
                                    <span class="block text-sm text-slate-500 dark:text-slate-400">{{ $feature->description }}</span>
                                </span>
                                <x-badge tone="muted">Always on</x-badge>
                            </label>
                        @endforeach
                        @foreach ($this->offerings['core'] as $feature)
                            <label class="flex cursor-pointer items-center gap-3">
                                <input wire:model.live="core" type="checkbox" value="{{ $feature->key }}" class="rounded border-slate-300 dark:border-white/20 dark:bg-ink text-brand shadow-sm focus:ring-brand dark:focus:ring-accent dark:focus:ring-offset-deep" />
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-slate-700 dark:text-slate-200">{{ $feature->label }}</span>
                                    <span class="block text-sm text-slate-500 dark:text-slate-400">{{ $feature->description }}</span>
                                </span>
                            </label>
                        @endforeach
                        <x-input-error :messages="$errors->get('core')" class="mt-1" />
                    </div>
                </x-card>

                <x-card>
                    <x-slot name="title">Modules</x-slot>
                    <div class="flex flex-col gap-3">
                        @if ($this->quoteLocked)
                            <x-alert tone="info" title="Quoted terms locked" :dismissible="false">
                                Invoice {{ $this->latestInvoice->invoice_no }} was issued for these terms, so modules stay as quoted.
                            </x-alert>
                        @endif
                        @foreach ($this->offerings['modules'] as $module)
                            <label class="flex cursor-pointer items-center gap-3">
                                <input wire:model.live="modules" type="checkbox" value="{{ $module->key }}" @disabled($this->quoteLocked) class="rounded border-slate-300 dark:border-white/20 dark:bg-ink text-brand shadow-sm focus:ring-brand dark:focus:ring-accent dark:focus:ring-offset-deep" />
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-slate-700 dark:text-slate-200">{{ $module->label }}</span>
                                    <span class="block text-sm text-slate-500 dark:text-slate-400">{{ $module->description }}</span>
                                </span>
                                <span class="shrink-0 text-sm font-medium text-slate-600 dark:text-slate-300">{{ $this->preview['currency'] }} {{ number_format($module->base_price * $this->preview['multiplier'], 2) }}</span>
                            </label>
                        @endforeach
                        <x-input-error :messages="$errors->get('modules')" class="mt-1" />
                        @if ($this->preview['apply_bundle'])
                            <p class="text-sm text-green-700 dark:text-green-400">{{ number_format($this->preview['bundle_discount_rate'] * 100, 0) }}% bundle discount applies ({{ count($this->preview['modules']) }} modules selected).</p>
                        @endif
                    </div>
                </x-card>

                <x-card>
                    <x-slot name="title">Caps &amp; term</x-slot>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="student_band" :value="__('Student band')" />
                            <x-select wire:model.live="student_band" id="student_band" name="student_band" class="mt-1 block w-full" :disabled="$this->quoteLocked">
                                <option value="">{{ __('No cap (band from heartbeats)') }}</option>
                                @foreach (Setting::corePricing() as $band)
                                    <option value="{{ $band['key'] }}">{{ $band['label'] }}{{ ! empty($band['custom']) ? ' — custom quote' : '' }}</option>
                                @endforeach
                            </x-select>
                            <x-input-error :messages="$errors->get('student_band')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="max_students" :value="__('Max active students')" />
                            <x-text-input wire:model.live.debounce.500ms="max_students" id="max_students" class="mt-1 block w-full" type="number" min="1" name="max_students" placeholder="No cap" :disabled="$this->quoteLocked" />
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Picking a band fills the cap; typing a cap re-selects its band. 3,501+ students needs a custom quote.</p>
                            <x-input-error :messages="$errors->get('max_students')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="starts_at" :value="__('Starts on')" />
                            <x-text-input wire:model.live="starts_at" id="starts_at" class="mt-1 block w-full" type="date" name="starts_at" />
                            <x-input-error :messages="$errors->get('starts_at')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="expires_at" :value="__('Expires on')" />
                            <x-text-input wire:model.live="expires_at" id="expires_at" class="mt-1 block w-full" type="date" name="expires_at" />
                            <x-input-error :messages="$errors->get('expires_at')" class="mt-2" />
                        </div>
                        <div class="sm:col-span-2">
                            <x-input-label for="notes" :value="__('Notes')" />
                            <x-text-input wire:model.live.debounce.500ms="notes" id="notes" class="mt-1 block w-full" type="text" name="notes" placeholder="{{ $this->preview['is_custom'] ? 'Custom quote — describe the manual terms for ops' : 'Internal notes, never sent to the school' }}" />
                            <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                        </div>
                    </div>
                </x-card>

                <x-card>
                    <x-slot name="title">Quote details</x-slot>
                    @if ($this->quoteLocked)
                        <x-alert tone="info" title="Quoted terms locked" :dismissible="false" class="mb-4">
                            Invoice {{ $this->latestInvoice->invoice_no }} was issued for these terms, so the quote stays as quoted.
                        </x-alert>
                    @endif
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="hosting_mode" :value="__('Hosting mode')" />
                            <x-select wire:model.live="hosting_mode" id="hosting_mode" name="hosting_mode" required class="mt-1 block w-full" :disabled="$this->quoteLocked">
                                @foreach ($this->quoteMeta['hostingOptions'] as $mode => $option)
                                    <option value="{{ $mode }}">{{ $option['label'] }} — {{ $this->preview['currency'] }} {{ number_format($option['fee'], 2) }} one-time</option>
                                @endforeach
                            </x-select>
                            <x-input-error :messages="$errors->get('hosting_mode')" class="mt-2" />
                        </div>
                        <div class="flex flex-col gap-3">
                            <label class="flex cursor-pointer items-center gap-3">
                                <input wire:model.live="config_setup" type="checkbox" @disabled($this->quoteLocked) class="rounded border-slate-300 dark:border-white/20 dark:bg-ink text-brand shadow-sm focus:ring-brand dark:focus:ring-accent dark:focus:ring-offset-deep" />
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-slate-700 dark:text-slate-200">{{ __('System configuration & data entry') }}</span>
                                     <span class="block text-sm text-slate-500 dark:text-slate-400">{{ $this->preview['currency'] }} {{ number_format($this->quoteMeta['configSetupFee'], 2) }} one-time</span>
                                </span>
                            </label>
                            <label class="flex cursor-pointer items-center gap-3">
                                <input wire:model.live="migration" type="checkbox" @disabled($this->quoteLocked) class="rounded border-slate-300 dark:border-white/20 dark:bg-ink text-brand shadow-sm focus:ring-brand dark:focus:ring-accent dark:focus:ring-offset-deep" />
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-slate-700 dark:text-slate-200">{{ __('Legacy data migration') }}</span>
                                     <span class="block text-sm text-slate-500 dark:text-slate-400">{{ $this->preview['currency'] }} {{ number_format($this->quoteMeta['migrationFee'], 2) }} one-time</span>
                                </span>
                            </label>
                        </div>
                        @php($rates = $this->quoteMeta['rates'])
                        <div>
                            <x-input-label for="training_admin" :value="__('Remote admin trainings')" />
                            <x-text-input wire:model.live.debounce.500ms="training_admin" id="training_admin" class="mt-1 block w-full" type="number" min="0" name="training_admin" :disabled="$this->quoteLocked" />
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $this->preview['currency'] }} {{ number_format($rates['admin'], 2) }} per session</p>
                            <x-input-error :messages="$errors->get('training_admin')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="training_teacher" :value="__('Remote lecturer trainings')" />
                            <x-text-input wire:model.live.debounce.500ms="training_teacher" id="training_teacher" class="mt-1 block w-full" type="number" min="0" name="training_teacher" :disabled="$this->quoteLocked" />
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $this->preview['currency'] }} {{ number_format($rates['teacher'], 2) }} per session</p>
                            <x-input-error :messages="$errors->get('training_teacher')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="training_onsite" :value="__('On-site training days')" />
                            <x-text-input wire:model.live.debounce.500ms="training_onsite" id="training_onsite" class="mt-1 block w-full" type="number" min="0" name="training_onsite" :disabled="$this->quoteLocked" />
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $this->preview['currency'] }} {{ number_format($rates['onsite'], 2) }} per day</p>
                            <x-input-error :messages="$errors->get('training_onsite')" class="mt-2" />
                        </div>
                        <label class="flex cursor-pointer items-center gap-3 sm:col-span-2">
                            <input wire:model.live="founding_client" type="checkbox" @disabled($this->quoteLocked) class="rounded border-slate-300 dark:border-white/20 dark:bg-ink text-brand shadow-sm focus:ring-brand dark:focus:ring-accent dark:focus:ring-offset-deep" />
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium text-slate-700 dark:text-slate-200">{{ __('Founding client') }}</span>
                                <span class="block text-sm text-slate-500 dark:text-slate-400">{{ number_format($this->preview['founding_discount_rate'] * 100, 0) }}% off the core, upfront and renewal.</span>
                            </span>
                        </label>
                    </div>
                    </x-card>
                    </div>

                    <aside class="min-w-0 lg:col-span-2 lg:sticky lg:top-6">
                <x-card>
                    <x-slot name="title">Quote preview</x-slot>
                    <x-slot name="actions">
                        <span class="flex items-center gap-2">
                            <span wire:loading.delay class="text-xs font-normal text-slate-400 dark:text-slate-500">Updating…</span>
                            <x-badge tone="muted">{{ $this->preview['band_label'] }}</x-badge>
                        </span>
                    </x-slot>
                    @if ($this->preview['is_custom'])
                        <x-alert tone="warn" title="Custom quote required" :dismissible="false">
                            This school is past the auto-priced bands, so there is no automatic total.
                            Record the agreed terms in Notes so ops can quote manually.
                        </x-alert>
                    @else
                        <div class="flex flex-col gap-6" wire:loading.class.delay="opacity-60">
                            <section aria-label="Upfront total">
                                <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Upfront</h3>
                                <dl class="mt-2 flex flex-col gap-2">
                                    <div class="flex items-center justify-between gap-4 text-sm">
                                        <dt class="text-slate-600 dark:text-slate-300">Core upfront</dt>
                                        <dd class="font-medium text-slate-900 dark:text-white">{{ $this->preview['currency'] }} {{ number_format($this->preview['core_upfront_final'], 2) }}</dd>
                                    </div>
                                    @if ($this->preview['founding_discount_upfront'] > 0)
                                        <div class="flex items-center justify-between gap-4 text-sm">
                                            <dt class="text-slate-600 dark:text-slate-300">Founding-client discount ({{ number_format($this->preview['founding_discount_rate'] * 100, 0) }}% off core)</dt>
                                            <dd class="font-medium text-green-700 dark:text-green-400">−{{ $this->preview['currency'] }} {{ number_format($this->preview['founding_discount_upfront'], 2) }}</dd>
                                        </div>
                                    @endif
                                    @foreach ($this->preview['modules'] as $module)
                                        <div class="flex items-center justify-between gap-4 text-sm">
                                            <dt class="text-slate-600 dark:text-slate-300">{{ $module['label'] }}</dt>
                                            <dd class="font-medium text-slate-900 dark:text-white">{{ $this->preview['currency'] }} {{ number_format($module['onetime'], 2) }}</dd>
                                        </div>
                                    @endforeach
                                    @if ($this->preview['bundle_discount_onetime'] > 0)
                                        <div class="flex items-center justify-between gap-4 text-sm">
                                            <dt class="text-slate-600 dark:text-slate-300">Bundle discount ({{ number_format($this->preview['bundle_discount_rate'] * 100, 0) }}% off modules)</dt>
                                            <dd class="font-medium text-green-700 dark:text-green-400">−{{ $this->preview['currency'] }} {{ number_format($this->preview['bundle_discount_onetime'], 2) }}</dd>
                                        </div>
                                    @endif
                                    <div class="flex items-center justify-between gap-4 text-sm">
                                        <dt class="text-slate-600 dark:text-slate-300">Hosting — {{ $this->preview['hosting_label'] }}</dt>
                                        <dd class="font-medium text-slate-900 dark:text-white">{{ $this->preview['currency'] }} {{ number_format($this->preview['hosting_setup_fee'], 2) }}</dd>
                                    </div>
                                    @foreach ($this->preview['addons'] as $addon)
                                        <div class="flex items-center justify-between gap-4 text-sm">
                                            <dt class="text-slate-600 dark:text-slate-300">{{ $addon['label'] }}</dt>
                                            <dd class="font-medium text-slate-900 dark:text-white">{{ $this->preview['currency'] }} {{ number_format($addon['price'], 2) }}</dd>
                                        </div>
                                    @endforeach
                                    @foreach ($this->preview['trainings'] as $training)
                                        <div class="flex items-center justify-between gap-4 text-sm">
                                            <dt class="text-slate-600 dark:text-slate-300">{{ $training['label'] }}</dt>
                                            <dd class="font-medium text-slate-900 dark:text-white">{{ $this->preview['currency'] }} {{ number_format($training['price'], 2) }}</dd>
                                        </div>
                                    @endforeach
                                    <div class="flex items-center justify-between gap-4 border-t border-slate-200 pt-2 text-sm dark:border-white/10">
                                        <dt class="font-semibold text-slate-900 dark:text-white">Upfront total</dt>
                                        <dd class="font-semibold text-slate-900 dark:text-white">{{ $this->preview['currency'] }} {{ number_format($this->preview['upfront_total'], 2) }}</dd>
                                    </div>
                                </dl>
                            </section>
                            <section aria-label="Renewal total">
                                <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Renewal</h3>
                                <dl class="mt-2 flex flex-col gap-2">
                                    <div class="flex items-center justify-between gap-4 text-sm">
                                        <dt class="text-slate-600 dark:text-slate-300">Core renewal</dt>
                                        <dd class="font-medium text-slate-900 dark:text-white">{{ $this->preview['currency'] }} {{ number_format($this->preview['core_renewal_final'], 2) }}</dd>
                                    </div>
                                    @if ($this->preview['founding_discount_renew'] > 0)
                                        <div class="flex items-center justify-between gap-4 text-sm">
                                            <dt class="text-slate-600 dark:text-slate-300">Founding-client discount ({{ number_format($this->preview['founding_discount_rate'] * 100, 0) }}% off core)</dt>
                                            <dd class="font-medium text-green-700 dark:text-green-400">−{{ $this->preview['currency'] }} {{ number_format($this->preview['founding_discount_renew'], 2) }}</dd>
                                        </div>
                                    @endif
                                    @foreach ($this->preview['modules'] as $module)
                                        <div class="flex items-center justify-between gap-4 text-sm">
                                            <dt class="text-slate-600 dark:text-slate-300">{{ $module['label'] }} renewal</dt>
                                            <dd class="font-medium text-slate-900 dark:text-white">{{ $this->preview['currency'] }} {{ number_format($module['renew'], 2) }}</dd>
                                        </div>
                                    @endforeach
                                    @if ($this->preview['bundle_discount_renew'] > 0)
                                        <div class="flex items-center justify-between gap-4 text-sm">
                                            <dt class="text-slate-600 dark:text-slate-300">Bundle discount ({{ number_format($this->preview['bundle_discount_rate'] * 100, 0) }}% off modules)</dt>
                                            <dd class="font-medium text-green-700 dark:text-green-400">−{{ $this->preview['currency'] }} {{ number_format($this->preview['bundle_discount_renew'], 2) }}</dd>
                                        </div>
                                    @endif
                                    <div class="flex items-center justify-between gap-4 border-t border-slate-200 pt-2 text-sm dark:border-white/10">
                                        <dt class="font-semibold text-slate-900 dark:text-white">Renewal total</dt>
                                        <dd class="font-semibold text-slate-900 dark:text-white">{{ $this->preview['currency'] }} {{ number_format($this->preview['renew_total'], 2) }}</dd>
                                    </div>
                                </dl>
                            </section>
                        </div>
                    @endif
                    <x-slot name="footer">Read-only estimate from settings storage; saving does not bill anything.</x-slot>
                </x-card>

                        <div class="flex flex-col gap-2">
                            <x-primary-button wire:loading.attr="disabled" wire:target="save" class="w-full justify-center">
                                <span wire:loading.remove wire:target="save">{{ __('Save licence') }}</span>
                                <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                            </x-primary-button>
                            @if ($this->pendingInvoice)
                                <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-400/20 dark:bg-amber-500/10">
                                    <p class="text-sm font-medium text-amber-800 dark:text-amber-200">Pending invoice {{ $this->pendingInvoice->invoice_no }}</p>
                                    <p class="mt-1 text-sm text-amber-700 dark:text-amber-300/80">Settle or delete it before generating a new one.</p>
                                    <x-button-link :href="route('licences.invoices.show', [$deployment->uuid, $this->pendingInvoice->id])" variant="tertiary" class="mt-2">
                                        {{ __('View invoice') }}
                                    </x-button-link>
                                </div>
                            @elseif ($this->quoteDirty)
                                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                                    <p class="text-sm font-medium text-slate-700 dark:text-slate-200">Unsaved quote changes</p>
                                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Save the licence first — invoices bill the saved terms.</p>
                                </div>
                            @else
                                <x-secondary-button type="button" wire:click="openInvoiceModal" wire:loading.attr="disabled" class="w-full justify-center">
                                    <span wire:loading.remove wire:target="openInvoiceModal">{{ __('Create invoice') }}</span>
                                    <span wire:loading wire:target="openInvoiceModal">{{ __('Preparing…') }}</span>
                                </x-secondary-button>
                            @endif
                            @if ($deployment->latestLicence !== null)
                                <x-secondary-button type="button" x-data="" x-on:click.prevent="$dispatch('open-modal', 'confirm-licence-renew')" class="w-full justify-center">
                                    {{ __('Renew licence') }}
                                </x-secondary-button>
                            @endif
                        </div>

                        @if ($this->exportableLicence !== null)
                            <x-card>
                                <x-slot name="title">Licence file</x-slot>
                                @if ($this->exportFileJson !== null)
                                    <div class="flex flex-col gap-3">
                                        <p class="text-sm text-slate-500 dark:text-slate-400">Signed air-gap file for this deployment. Download it or paste the text into FlowEdu.</p>
                                        <x-button-link :href="route('licences.export', $deployment->uuid)" variant="tertiary" class="justify-center">
                                            {{ __('Download signed file') }}
                                        </x-button-link>
                                        <div x-data="{ copied: false }">
                                            <textarea x-ref="export" readonly rows="8" class="block w-full rounded-md border-slate-300 font-mono text-xs shadow-sm focus:border-brand focus:ring-brand dark:border-white/15 dark:bg-ink dark:text-slate-100 dark:focus:border-accent dark:focus:ring-accent">{!! $this->exportFileJson !!}</textarea>
                                            <x-secondary-button type="button" @click="navigator.clipboard.writeText($refs.export.value); copied = true" x-text="copied ? 'Copied' : 'Copy text'" class="mt-2 w-full justify-center" />
                                        </div>
                                    </div>
                                @else
                                    <x-alert tone="warn" title="Signing key missing" :dismissible="false">
                                        Set LICENCE_SIGNING_KEY before exporting licence files.
                                    </x-alert>
                                @endif
                            </x-card>
                        @endif
                    </aside>
                </div>
            </form>

            <x-modal name="confirm-licence-renew" focusable>
                <form wire:submit="renew" class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        {{ __('Renew this licence?') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('A new row carries the current terms forward one more year. History is preserved.') }}
                    </p>
                    <div class="mt-6 flex justify-end">
                        <x-secondary-button x-on:click="$dispatch('close')">
                            {{ __('Cancel') }}
                        </x-secondary-button>
                        <x-primary-button class="ms-3" wire:loading.attr="disabled" wire:target="renew">
                            <span wire:loading.remove wire:target="renew">{{ __('Renew') }}</span>
                            <span wire:loading wire:target="renew">{{ __('Renewing…') }}</span>
                        </x-primary-button>
                    </div>
                </form>
            </x-modal>

            <x-modal name="invoice-form" focusable>
                <form wire:submit="createInvoice" class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        {{ __('Generate invoice?') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('Review what will be billed. Groups start collapsed — open one to inspect its lines. Generating stores the invoice and opens the printable copy.') }}
                    </p>

                    @if ($this->preview['is_custom'])
                        <div class="mt-4">
                            <x-alert tone="warn" title="Custom quote" :dismissible="false">
                                {{ __('No automatic total — the invoice records the custom band (:band).', ['band' => $this->preview['band_label']]) }}
                            </x-alert>
                        </div>
                    @else
                        <div class="mt-4 flex flex-col gap-3">
                            <details class="rounded-lg border border-slate-200 dark:border-white/10">
                                <summary class="flex cursor-pointer items-center justify-between gap-4 px-4 py-3 text-sm font-medium text-slate-700 dark:text-slate-200">
                                    <span>{{ __('Core') }}</span>
                                    <span>{{ $this->preview['currency'] }} {{ number_format($this->preview['core_upfront_final'], 2) }}</span>
                                </summary>
                                <dl class="flex flex-col gap-2 border-t border-slate-200 px-4 py-3 dark:border-white/10">
                                    <div class="flex items-center justify-between gap-4 text-sm">
                                        <dt class="text-slate-600 dark:text-slate-300">{{ __('Core upfront') }}</dt>
                                        <dd class="font-medium text-slate-900 dark:text-white">{{ $this->preview['currency'] }} {{ number_format($this->preview['core_upfront_final'], 2) }}</dd>
                                    </div>
                                    @if ($this->preview['founding_discount_upfront'] > 0)
                                        <div class="flex items-center justify-between gap-4 text-sm">
                                            <dt class="text-slate-600 dark:text-slate-300">{{ __('Founding-client discount') }}</dt>
                                            <dd class="font-medium text-green-700 dark:text-green-400">−{{ $this->preview['currency'] }} {{ number_format($this->preview['founding_discount_upfront'], 2) }}</dd>
                                        </div>
                                    @endif
                                    <div class="flex items-center justify-between gap-4 text-sm">
                                        <dt class="text-slate-600 dark:text-slate-300">{{ __('Core renewal') }}</dt>
                                        <dd class="font-medium text-slate-900 dark:text-white">{{ $this->preview['currency'] }} {{ number_format($this->preview['core_renewal_final'], 2) }}</dd>
                                    </div>
                                </dl>
                            </details>

                            @if (count($this->preview['modules']) > 0)
                                <details class="rounded-lg border border-slate-200 dark:border-white/10">
                                    <summary class="flex cursor-pointer items-center justify-between gap-4 px-4 py-3 text-sm font-medium text-slate-700 dark:text-slate-200">
                                        <span>{{ __('Modules (:count)', ['count' => count($this->preview['modules'])]) }}</span>
                                        <span>{{ $this->preview['currency'] }} {{ number_format($this->preview['modules_onetime_final'], 2) }}</span>
                                    </summary>
                                    <dl class="flex flex-col gap-2 border-t border-slate-200 px-4 py-3 dark:border-white/10">
                                        @foreach ($this->preview['modules'] as $module)
                                            <div class="flex items-center justify-between gap-4 text-sm">
                                                <dt class="text-slate-600 dark:text-slate-300">{{ $module['label'] }}</dt>
                                                <dd class="font-medium text-slate-900 dark:text-white">{{ $this->preview['currency'] }} {{ number_format($module['onetime'], 2) }}</dd>
                                            </div>
                                        @endforeach
                                        @if ($this->preview['bundle_discount_onetime'] > 0)
                                            <div class="flex items-center justify-between gap-4 text-sm">
                                                <dt class="text-slate-600 dark:text-slate-300">{{ __('Bundle discount') }}</dt>
                                                <dd class="font-medium text-green-700 dark:text-green-400">−{{ $this->preview['currency'] }} {{ number_format($this->preview['bundle_discount_onetime'], 2) }}</dd>
                                            </div>
                                        @endif
                                    </dl>
                                </details>
                            @endif

                            <details class="rounded-lg border border-slate-200 dark:border-white/10">
                                <summary class="flex cursor-pointer items-center justify-between gap-4 px-4 py-3 text-sm font-medium text-slate-700 dark:text-slate-200">
                                    <span>{{ __('Hosting & integration') }}</span>
                                    <span>{{ $this->preview['currency'] }} {{ number_format($this->preview['hosting_setup_fee'] + $this->preview['configuration_fee'], 2) }}</span>
                                </summary>
                                <dl class="flex flex-col gap-2 border-t border-slate-200 px-4 py-3 dark:border-white/10">
                                    <div class="flex items-center justify-between gap-4 text-sm">
                                        <dt class="text-slate-600 dark:text-slate-300">{{ __('Hosting — :mode', ['mode' => $this->preview['hosting_label']]) }}</dt>
                                        <dd class="font-medium text-slate-900 dark:text-white">{{ $this->preview['currency'] }} {{ number_format($this->preview['hosting_setup_fee'], 2) }}</dd>
                                    </div>
                                    @foreach ($this->preview['addons'] as $addon)
                                        <div class="flex items-center justify-between gap-4 text-sm">
                                            <dt class="text-slate-600 dark:text-slate-300">{{ $addon['label'] }}</dt>
                                            <dd class="font-medium text-slate-900 dark:text-white">{{ $this->preview['currency'] }} {{ number_format($addon['price'], 2) }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </details>

                            @if (count($this->preview['trainings']) > 0)
                                <details class="rounded-lg border border-slate-200 dark:border-white/10">
                                    <summary class="flex cursor-pointer items-center justify-between gap-4 px-4 py-3 text-sm font-medium text-slate-700 dark:text-slate-200">
                                        <span>{{ __('Training') }}</span>
                                        <span>{{ $this->preview['currency'] }} {{ number_format($this->preview['training_fee'], 2) }}</span>
                                    </summary>
                                    <dl class="flex flex-col gap-2 border-t border-slate-200 px-4 py-3 dark:border-white/10">
                                        @foreach ($this->preview['trainings'] as $training)
                                            <div class="flex items-center justify-between gap-4 text-sm">
                                                <dt class="text-slate-600 dark:text-slate-300">{{ $training['label'] }}</dt>
                                                <dd class="font-medium text-slate-900 dark:text-white">{{ $this->preview['currency'] }} {{ number_format($training['price'], 2) }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                </details>
                            @endif

                            <div class="flex items-center justify-between gap-4 rounded-lg bg-slate-50 px-4 py-3 text-sm dark:bg-white/5">
                                <dt class="font-semibold text-slate-900 dark:text-white">{{ __('Upfront total') }}</dt>
                                <dd class="font-semibold text-slate-900 dark:text-white">{{ $this->preview['currency'] }} {{ number_format($this->preview['upfront_total'], 2) }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-4 px-4 text-sm">
                                <dt class="text-slate-600 dark:text-slate-300">{{ __('Renewal total') }}</dt>
                                <dd class="font-medium text-slate-900 dark:text-white">{{ $this->preview['currency'] }} {{ number_format($this->preview['renew_total'], 2) }}</dd>
                            </div>
                        </div>
                    @endif

                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="due_at" :value="__('Invoice due date')" />
                            <x-text-input wire:model="due_at" id="due_at" class="mt-1 block w-full" type="date" name="due_at" required />
                            <x-input-error :messages="$errors->get('due_at')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="next_payment_at" :value="__('Next payment date')" />
                            <x-text-input wire:model="next_payment_at" id="next_payment_at" class="mt-1 block w-full" type="date" name="next_payment_at" />
                            <x-input-error :messages="$errors->get('next_payment_at')" class="mt-2" />
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end gap-2">
                        <x-secondary-button type="button" x-on:click="$dispatch('close')">
                            {{ __('Cancel') }}
                        </x-secondary-button>
                        <x-primary-button class="ms-3" wire:loading.attr="disabled" wire:target="createInvoice">
                            <span wire:loading.remove wire:target="createInvoice">{{ __('Generate invoice') }}</span>
                            <span wire:loading wire:target="createInvoice">{{ __('Generating…') }}</span>
                        </x-primary-button>
                    </div>
                    <x-input-error :messages="$errors->get('invoice')" class="mt-2" />
                </form>
            </x-modal>
        </div>
    </div>
</div>
