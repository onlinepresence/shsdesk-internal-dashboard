<?php

use App\Models\Deployment;
use App\Models\Feature;
use App\Models\Licence;
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

    public ?string $starts_at = null;

    public ?string $expires_at = null;

    public ?string $notes = null;

    public string $hosting_mode = 'self_hosted';

    public $implementation_fee = null;

    public $config_fee = null;

    public $migration_fee = null;

    public $training_admin = 0;

    public $training_teacher = 0;

    public $training_onsite = 0;

    public bool $founding_client = false;

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
            $this->implementation_fee = Licence::DEFAULT_IMPLEMENTATION_FEE;
            $this->config_fee = Licence::DEFAULT_CONFIG_FEE;
            $this->migration_fee = Licence::DEFAULT_MIGRATION_FEE;

            return;
        }

        $this->core = array_keys(array_filter((array) $current->core));
        $this->modules = array_keys(array_filter((array) $current->modules));
        $this->max_students = $current->caps['max_active_students'] ?? null;
        $this->starts_at = $current->starts_at?->format('Y-m-d');
        $this->expires_at = $current->expires_at?->format('Y-m-d');
        $this->notes = $current->notes;
        $this->hosting_mode = $current->hosting_mode ?? 'self_hosted';
        $this->implementation_fee = $current->implementation_fee;
        $this->config_fee = $current->config_fee;
        $this->migration_fee = $current->migration_fee;
        $this->training_admin = $current->training_admin ?? 0;
        $this->training_teacher = $current->training_teacher ?? 0;
        $this->training_onsite = $current->training_onsite ?? 0;
        $this->founding_client = (bool) $current->founding_client;
    }

    /**
     * Read-only annual preview for the current form state.
     *
     * @return array{currency: string, band: string, multiplier: float, lines: list<array{label: string, amount: float}>, discount: float, founding_discount: float, total: float}
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

        $payload = [
            'core' => array_fill_keys($validated['core'] ?? [], true),
            'modules' => array_fill_keys($validated['modules'] ?? [], true),
            'caps' => $this->maxStudents() !== null ? ['max_active_students' => $this->maxStudents()] : null,
            'price_snapshot' => Licence::priceSnapshot(
                array_fill_keys($validated['modules'] ?? [], true),
                $this->maxStudents(),
                $this->reportedStudents(),
                $this->quoteInput(),
            ),
            'starts_at' => $validated['starts_at'],
            'expires_at' => $validated['expires_at'],
            'notes' => $validated['notes'],
            'hosting_mode' => $validated['hosting_mode'],
            'implementation_fee' => $this->moneyOrNull($validated['implementation_fee'] ?? null),
            'config_fee' => $this->moneyOrNull($validated['config_fee'] ?? null),
            'migration_fee' => $this->moneyOrNull($validated['migration_fee'] ?? null),
            'training_admin' => (int) ($validated['training_admin'] ?? 0),
            'training_teacher' => (int) ($validated['training_teacher'] ?? 0),
            'training_onsite' => (int) ($validated['training_onsite'] ?? 0),
            'founding_client' => (bool) ($validated['founding_client'] ?? false),
        ];

        $current = $this->deployment->latestLicence;

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
                'hosting_mode', 'implementation_fee', 'config_fee', 'migration_fee',
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
                    'implementation_fee' => $current->implementation_fee !== null ? (float) $current->implementation_fee : null,
                    'config_fee' => $current->config_fee !== null ? (float) $current->config_fee : null,
                    'migration_fee' => $current->migration_fee !== null ? (float) $current->migration_fee : null,
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
            'implementation_fee' => $current->implementation_fee,
            'config_fee' => $current->config_fee,
            'migration_fee' => $current->migration_fee,
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
            'hosting_mode' => ['required', 'string', Rule::in(Licence::HOSTING_MODES)],
            'implementation_fee' => ['nullable', 'numeric', 'min:0'],
            'config_fee' => ['nullable', 'numeric', 'min:0'],
            'migration_fee' => ['nullable', 'numeric', 'min:0'],
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

    protected function normalizeBlanks(): void
    {
        foreach (['starts_at', 'expires_at', 'notes', 'implementation_fee', 'config_fee', 'migration_fee'] as $field) {
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
     * Quote dimensions for the preview and snapshot, with money rounded
     * to pesewas and counts coerced to integers.
     *
     * @return array{hosting_mode: ?string, implementation_fee: ?float, config_fee: ?float, migration_fee: ?float, training_admin: int, training_teacher: int, training_onsite: int, founding_client: bool}
     */
    protected function quoteInput(): array
    {
        return [
            'hosting_mode' => $this->hosting_mode,
            'implementation_fee' => $this->moneyOrNull($this->implementation_fee),
            'config_fee' => $this->moneyOrNull($this->config_fee),
            'migration_fee' => $this->moneyOrNull($this->migration_fee),
            'training_admin' => (int) ($this->training_admin ?? 0),
            'training_teacher' => (int) ($this->training_teacher ?? 0),
            'training_onsite' => (int) ($this->training_onsite ?? 0),
            'founding_client' => (bool) $this->founding_client,
        ];
    }

    protected function moneyOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }

    /**
     * Latest reported student count, if this deployment ever checked in.
     */
    protected function reportedStudents(): ?int
    {
        return $this->deployment->heartbeats()->latest()->first()?->students;
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

<div class="py-12">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="mb-6 flex flex-col gap-6">
            <x-section-title :title="__('Licence — ').$deployment->school_name" subtitle="Terms in force for this deployment.">
                <x-button-link :href="route('licences.index')" wire:navigate variant="tertiary">
                    {{ __('Back to licences') }}
                </x-button-link>
            </x-section-title>

            <form wire:submit="save" class="flex flex-col gap-6">
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
                                <input wire:model="core" type="checkbox" value="{{ $feature->key }}" class="rounded border-slate-300 dark:border-white/20 dark:bg-ink text-brand shadow-sm focus:ring-brand dark:focus:ring-accent dark:focus:ring-offset-deep" />
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
                        @foreach ($this->offerings['modules'] as $module)
                            <label class="flex cursor-pointer items-center gap-3">
                                <input wire:model="modules" type="checkbox" value="{{ $module->key }}" class="rounded border-slate-300 dark:border-white/20 dark:bg-ink text-brand shadow-sm focus:ring-brand dark:focus:ring-accent dark:focus:ring-offset-deep" />
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-slate-700 dark:text-slate-200">{{ $module->label }}</span>
                                    <span class="block text-sm text-slate-500 dark:text-slate-400">{{ $module->description }}</span>
                                </span>
                                <span class="shrink-0 text-sm font-medium text-slate-600 dark:text-slate-300">{{ $this->preview['currency'] }} {{ number_format($module->base_price, 2) }}/yr</span>
                            </label>
                        @endforeach
                        <x-input-error :messages="$errors->get('modules')" class="mt-1" />
                    </div>
                </x-card>

                <x-card>
                    <x-slot name="title">Caps &amp; term</x-slot>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="max_students" :value="__('Max active students')" />
                            <x-text-input wire:model="max_students" id="max_students" class="mt-1 block w-full" type="number" min="1" name="max_students" placeholder="No cap" />
                            <x-input-error :messages="$errors->get('max_students')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="starts_at" :value="__('Starts on')" />
                            <x-text-input wire:model="starts_at" id="starts_at" class="mt-1 block w-full" type="date" name="starts_at" />
                            <x-input-error :messages="$errors->get('starts_at')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="expires_at" :value="__('Expires on')" />
                            <x-text-input wire:model="expires_at" id="expires_at" class="mt-1 block w-full" type="date" name="expires_at" />
                            <x-input-error :messages="$errors->get('expires_at')" class="mt-2" />
                        </div>
                        <div class="sm:col-span-2">
                            <x-input-label for="notes" :value="__('Notes')" />
                            <x-text-input wire:model="notes" id="notes" class="mt-1 block w-full" type="text" name="notes" placeholder="Internal notes, never sent to the school" />
                            <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                        </div>
                    </div>
                </x-card>

                <x-card>
                    <x-slot name="title">Quote details</x-slot>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="hosting_mode" :value="__('Hosting mode')" />
                            <x-select wire:model="hosting_mode" id="hosting_mode" name="hosting_mode" required class="mt-1 block w-full">
                                @foreach (Licence::HOSTING_MODES as $mode)
                                    <option value="{{ $mode }}">{{ Licence::hostingLabels()[$mode] }}</option>
                                @endforeach
                            </x-select>
                            <x-input-error :messages="$errors->get('hosting_mode')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="implementation_fee" :value="__('Implementation fee')" />
                            <x-text-input wire:model="implementation_fee" id="implementation_fee" class="mt-1 block w-full" type="number" min="0" step="0.01" name="implementation_fee" placeholder="Defaults to {{ number_format(Licence::DEFAULT_IMPLEMENTATION_FEE, 2) }}" />
                            <x-input-error :messages="$errors->get('implementation_fee')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="config_fee" :value="__('Configuration fee')" />
                            <x-text-input wire:model="config_fee" id="config_fee" class="mt-1 block w-full" type="number" min="0" step="0.01" name="config_fee" placeholder="Defaults to {{ number_format(Licence::DEFAULT_CONFIG_FEE, 2) }}" />
                            <x-input-error :messages="$errors->get('config_fee')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="migration_fee" :value="__('Migration fee')" />
                            <x-text-input wire:model="migration_fee" id="migration_fee" class="mt-1 block w-full" type="number" min="0" step="0.01" name="migration_fee" placeholder="Defaults to {{ number_format(Licence::DEFAULT_MIGRATION_FEE, 2) }}" />
                            <x-input-error :messages="$errors->get('migration_fee')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="training_admin" :value="__('Remote admin trainings')" />
                            <x-text-input wire:model="training_admin" id="training_admin" class="mt-1 block w-full" type="number" min="0" name="training_admin" />
                            <x-input-error :messages="$errors->get('training_admin')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="training_teacher" :value="__('Remote lecturer trainings')" />
                            <x-text-input wire:model="training_teacher" id="training_teacher" class="mt-1 block w-full" type="number" min="0" name="training_teacher" />
                            <x-input-error :messages="$errors->get('training_teacher')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="training_onsite" :value="__('On-site training days')" />
                            <x-text-input wire:model="training_onsite" id="training_onsite" class="mt-1 block w-full" type="number" min="0" name="training_onsite" />
                            <x-input-error :messages="$errors->get('training_onsite')" class="mt-2" />
                        </div>
                        <label class="flex cursor-pointer items-center gap-3 sm:col-span-2">
                            <input wire:model="founding_client" type="checkbox" class="rounded border-slate-300 dark:border-white/20 dark:bg-ink text-brand shadow-sm focus:ring-brand dark:focus:ring-accent dark:focus:ring-offset-deep" />
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium text-slate-700 dark:text-slate-200">{{ __('Founding client') }}</span>
                                <span class="block text-sm text-slate-500 dark:text-slate-400">{{ __('15% off the core annual, like the FlowEdu quote.') }}</span>
                            </span>
                        </label>
                    </div>
                </x-card>

                <x-card>
                    <x-slot name="title">Annual preview</x-slot>
                    <x-slot name="actions">
                        <x-badge tone="muted">{{ $this->preview['band'] }}</x-badge>
                    </x-slot>
                    <dl class="flex flex-col gap-2">
                        @foreach ($this->preview['lines'] as $line)
                            <div class="flex items-center justify-between gap-4 text-sm">
                                <dt class="text-slate-600 dark:text-slate-300">{{ $line['label'] }}</dt>
                                <dd class="font-medium text-slate-900 dark:text-white">{{ $this->preview['currency'] }} {{ number_format($line['amount'], 2) }}</dd>
                            </div>
                        @endforeach
                        @if ($this->preview['discount'] > 0)
                            <div class="flex items-center justify-between gap-4 text-sm">
                                <dt class="text-slate-600 dark:text-slate-300">All-modules discount</dt>
                                <dd class="font-medium text-green-700 dark:text-green-400">−{{ $this->preview['currency'] }} {{ number_format($this->preview['discount'], 2) }}</dd>
                            </div>
                        @endif
                        @if ($this->preview['founding_discount'] > 0)
                            <div class="flex items-center justify-between gap-4 text-sm">
                                <dt class="text-slate-600 dark:text-slate-300">Founding-client discount (15% off core)</dt>
                                <dd class="font-medium text-green-700 dark:text-green-400">−{{ $this->preview['currency'] }} {{ number_format($this->preview['founding_discount'], 2) }}</dd>
                            </div>
                        @endif
                        <div class="flex items-center justify-between gap-4 border-t border-slate-200 pt-2 text-sm dark:border-white/10">
                            <dt class="font-semibold text-slate-900 dark:text-white">Total × {{ $this->preview['multiplier'] }}</dt>
                            <dd class="font-semibold text-slate-900 dark:text-white">{{ $this->preview['currency'] }} {{ number_format($this->preview['total'], 2) }}</dd>
                        </div>
                    </dl>
                    <x-slot name="footer">Read-only estimate from the catalogue; saving does not bill anything.</x-slot>
                </x-card>

                <div class="flex flex-wrap items-center justify-end gap-2">
                    @if ($deployment->latestLicence !== null)
                        <x-secondary-button type="button" x-data="" x-on:click.prevent="$dispatch('open-modal', 'confirm-licence-renew')">
                            {{ __('Renew licence') }}
                        </x-secondary-button>
                    @endif
                    <x-primary-button wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">{{ __('Save licence') }}</span>
                        <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                    </x-primary-button>
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
        </div>
    </div>
</div>
