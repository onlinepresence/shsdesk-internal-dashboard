<?php

use App\Models\DemoKey;
use App\Models\Deployment;
use App\Models\EnrollmentCode;
use App\Models\Licence;
use App\Models\Product;
use App\Support\EnvWriter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public string $label = '';

    public ?string $expires_at = null;

    public bool $never = false;

    public bool $neverConfirmed = false;

    public ?string $host = null;

    public ?string $plainTextCode = null;

    public ?int $mintedId = null;

    public ?int $deletingId = null;

    public ?string $verificationKey = null;

    public ?int $convertingId = null;

    public string $convert_school_name = '';

    public string $convert_product = '';

    public ?string $convert_plainTextToken = null;

    public ?string $convert_deploymentUuid = null;

    #[Computed]
    public function demoKeys(): LengthAwarePaginator
    {
        return DemoKey::query()->with('convertedDeployment')->latest()->paginate(10);
    }

    /**
     * Products a converted deployment may register under, live from
     * the table.
     *
     * @return Collection<int, Product>
     */
    #[Computed]
    public function products(): Collection
    {
        return Product::orderBy('name')->get();
    }

    /**
     * Whether minting and exporting are blocked. Every document is
     * signed, so nothing here works without the seed.
     */
    #[Computed]
    public function signingKeyMissing(): bool
    {
        return empty(config('licence-export.signing_key'));
    }

    /**
     * Generate the Ed25519 seed into .env. Super-admins only; the
     * seed itself is never displayed — only derived keys leave here.
     */
    public function generateSigningKey(): void
    {
        $this->authorize('manage-catalogue');

        $seed = bin2hex(random_bytes(32));

        if (! app(EnvWriter::class)->ensurePresent('LICENCE_SIGNING_KEY', $seed)) {
            $this->addError('signing_key', __('LICENCE_SIGNING_KEY is already set. Nothing was written.'));

            return;
        }

        config()->set('licence-export.signing_key', $seed);

        activity('demo')
            ->causedBy(Auth::user())
            ->log('demo.signing_key_generated');

        unset($this->signingKeyMissing);
    }

    /**
     * Reveal the public verification key for pasting into FlowEdu
     * as DEMO_PUBLIC_KEY. Public by design — safe to display.
     */
    public function showVerificationKey(): void
    {
        $this->verificationKey = Licence::verificationKeyHex();
    }

    public function openMintModal(): void
    {
        $this->reset(['label', 'host', 'neverConfirmed', 'plainTextCode', 'mintedId']);
        $this->expires_at = now()->addMinutes(DemoKey::DEFAULT_EXPIRY_MINUTES)->format('Y-m-d\TH:i');
        $this->never = false;
        $this->resetValidation();
        $this->dispatch('open-demo-form');
    }

    public function cancelMint(): void
    {
        $this->reset(['label', 'expires_at', 'host', 'neverConfirmed', 'plainTextCode', 'mintedId']);
        $this->never = false;
        $this->resetValidation();
        $this->dispatch('close-demo-form');
    }

    /**
     * Mint a key. Blank expiry falls back to the 30-minute default;
     * a never-expiring key stops at an explicit confirm first — it
     * outlives every rotation around it. Features always come from
     * licence rows, so the form offers no scope at all.
     */
    public function mint(): void
    {
        if ($this->signingKeyMissing) {
            $this->addError('signing_key', __('Set LICENCE_SIGNING_KEY before minting — generate one above or set it server-side.'));

            return;
        }

        if ($this->expires_at === '') {
            $this->expires_at = null;
        }

        if ($this->host === '') {
            $this->host = null;
        }

        $validated = $this->validate([
            'label' => ['required', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'never' => ['boolean'],
            'host' => ['nullable', 'string', 'max:255'],
        ]);

        if ($this->never && ! $this->neverConfirmed) {
            $this->dispatch('open-demo-never-confirm');

            return;
        }

        $minted = DemoKey::mintFor(
            $validated['label'],
            $this->never ? null : ($validated['expires_at'] ?? now()->addMinutes(DemoKey::DEFAULT_EXPIRY_MINUTES)->toDateTimeString()),
            $validated['host'],
        );

        activity('demo')
            ->performedOn($minted['record'])
            ->causedBy(Auth::user())
            ->withProperties([
                'expires_at' => $minted['record']->expires_at?->toIso8601String(),
                'host' => $minted['record']->host,
            ])
            ->log('demo.minted');

        $this->reset(['label', 'expires_at', 'host', 'neverConfirmed']);
        $this->never = false;
        $this->plainTextCode = $minted['code'];
        $this->mintedId = $minted['record']->id;
        $this->dispatch('close-demo-form');
        $this->dispatch('close-demo-never-confirm');
    }

    /**
     * Confirm the never-expires warning and finish minting.
     */
    public function mintConfirmed(): void
    {
        $this->neverConfirmed = true;
        $this->mint();
    }

    public function cancelNeverConfirm(): void
    {
        $this->neverConfirmed = false;
        $this->dispatch('close-demo-never-confirm');
    }

    public function cancelDelete(): void
    {
        $this->reset(['deletingId']);
        $this->resetValidation();
        $this->dispatch('close-demo-delete');
    }

    public function openConvertModal(int $id): void
    {
        $key = DemoKey::findOrFail($id);

        if ($key->isConverted()) {
            $this->addError('convert', __('This key already became a live deployment.'));

            return;
        }

        $this->reset(['convert_school_name', 'convert_product', 'convert_plainTextToken', 'convert_deploymentUuid']);
        $this->convertingId = $key->id;
        $this->convert_school_name = $key->label;
        $this->convert_product = $this->products->firstWhere('slug', 'flowedu')?->slug ?? $this->products->first()?->slug ?? '';
        $this->resetValidation();
        $this->dispatch('open-demo-convert');
    }

    public function cancelConvert(): void
    {
        $this->reset(['convertingId', 'convert_school_name', 'convert_product', 'convert_plainTextToken', 'convert_deploymentUuid']);
        $this->resetValidation();
        $this->dispatch('close-demo-convert');
    }

    /**
     * Turn a demo key into a live deployment: register the school,
     * mint its first claim code, and retire the demo key — all in
     * one transaction so partial conversions never persist. Works
     * on active and expired keys alike, and on revoked keys that
     * were never converted.
     */
    public function convert(): void
    {
        $key = DemoKey::findOrFail($this->convertingId);

        if ($key->isConverted()) {
            $this->addError('convert', __('This key already became a live deployment.'));

            return;
        }

        $validated = $this->validate([
            'convert_school_name' => ['required', 'string', 'max:255'],
            'convert_product' => ['required', 'string', 'exists:products,slug'],
        ]);

        $converted = DB::transaction(function () use ($key, $validated): array {
            $deployment = Deployment::create([
                'school_name' => $validated['convert_school_name'],
                'product' => $validated['convert_product'],
            ]);

            $minted = EnrollmentCode::mintFor($deployment);

            $key->markRevoked();
            $key->markConverted($deployment);

            activity('demo')
                ->performedOn($key)
                ->causedBy(Auth::user())
                ->withProperties([
                    'deployment_id' => $deployment->id,
                    'deployment_uuid' => $deployment->uuid,
                ])
                ->log('demo.converted');

            return ['deployment' => $deployment, 'code' => $minted['code']];
        });

        $this->convert_plainTextToken = $converted['code'];
        $this->convert_deploymentUuid = $converted['deployment']->uuid;
        $this->reset(['convertingId']);
        $this->dispatch('close-demo-convert');
    }

    /**
     * Retire a key. Offline copies keep verifying until they lapse —
     * revocation bites on the next online check.
     */
    public function delete(): void
    {
        $key = DemoKey::findOrFail($this->deletingId);

        $key->markRevoked();

        activity('demo')
            ->performedOn($key)
            ->causedBy(Auth::user())
            ->log('demo.revoked');

        $this->reset(['deletingId']);
        $this->dispatch('close-demo-delete');

        session()->flash('status', __('Demo key revoked.'));
    }
}; ?>

<div class="py-12" x-data="{}" x-on:open-demo-form.window="$dispatch('open-modal', 'demo-form')" x-on:close-demo-form.window="$dispatch('close-modal', 'demo-form')" x-on:open-demo-never-confirm.window="$dispatch('open-modal', 'demo-never-confirm')" x-on:close-demo-never-confirm.window="$dispatch('close-modal', 'demo-never-confirm')" x-on:open-demo-delete.window="$dispatch('open-modal', 'demo-delete')" x-on:close-demo-delete.window="$dispatch('close-modal', 'demo-delete')" x-on:open-demo-convert.window="$dispatch('open-modal', 'demo-convert')" x-on:close-demo-convert.window="$dispatch('close-modal', 'demo-convert')">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="mb-6 flex flex-col gap-6">
            <x-section-title title="Demo keys" subtitle="Signed demo credentials. Validity and host binding live inside the signature — verifiers trust nothing else.">
                @if (! $this->signingKeyMissing)
                    <x-secondary-button type="button" wire:click="showVerificationKey" wire:loading.attr="disabled" wire:target="showVerificationKey">
                        {{ __('Show verification key') }}
                    </x-secondary-button>
                @endif
                <x-primary-button type="button" wire:click="openMintModal">
                    {{ __('Mint key') }}
                </x-primary-button>
            </x-section-title>

            <x-alert flash="status" tone="success" />

            @if ($this->signingKeyMissing)
                <x-alert tone="warn" title="Signing key missing" :dismissible="false">
                    {{ __('Minting and exporting sign every document with LICENCE_SIGNING_KEY, which is not set. Nothing here can mint or export until it exists.') }}
                    <x-slot name="actions">
                        @can('manage-catalogue')
                            <x-secondary-button type="button" wire:click="generateSigningKey" wire:loading.attr="disabled" wire:target="generateSigningKey">
                                <span wire:loading.remove wire:target="generateSigningKey">{{ __('Generate signing key') }}</span>
                                <span wire:loading wire:target="generateSigningKey">{{ __('Generating…') }}</span>
                            </x-secondary-button>
                        @endcan
                    </x-slot>
                    <x-input-error :messages="$errors->get('signing_key')" class="mt-2" />
                </x-alert>
            @elseif ($verificationKey !== null)
                <x-alert tone="info" title="Verification key — paste into FlowEdu as DEMO_PUBLIC_KEY" :dismissible="false">
                    <div x-data="{ copied: false }" class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center">
                        <code x-ref="verifykey" class="min-w-0 flex-1 break-all font-mono text-sm">{{ $verificationKey }}</code>
                        <x-secondary-button type="button" @click="navigator.clipboard.writeText($refs.verifykey.innerText.trim()); copied = true" x-text="copied ? 'Copied' : 'Copy'" />
                    </div>
                </x-alert>
            @endif

            @if ($plainTextCode !== null)
                <x-alert tone="warn" title="New key minted — copy it now" :dismissible="false">
                    <div x-data="{ copied: false }" class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center">
                        <code x-ref="code" class="min-w-0 flex-1 break-all font-mono text-sm">{{ $plainTextCode }}</code>
                        <x-secondary-button type="button" @click="navigator.clipboard.writeText($refs.code.innerText.trim()); copied = true" x-text="copied ? 'Copied' : 'Copy'" />
                        @if ($mintedId !== null && ! $this->signingKeyMissing)
                            <x-button-link :href="route('demo-keys.download', $mintedId)" variant="tertiary">
                                {{ __('Download file') }}
                            </x-button-link>
                        @endif
                    </div>
                </x-alert>
            @endif

            @if ($convert_plainTextToken !== null)
                <x-alert tone="warn" title="Deployment live — enrollment code (copy once)" :dismissible="false">
                    <div x-data="{ copied: false }" class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center">
                        <code x-ref="converttoken" class="min-w-0 flex-1 break-all font-mono text-sm">{{ $convert_plainTextToken }}</code>
                        <x-secondary-button type="button" @click="navigator.clipboard.writeText($refs.converttoken.innerText.trim()); copied = true" x-text="copied ? 'Copied' : 'Copy'" />
                        @if ($convert_deploymentUuid !== null)
                            <x-button-link :href="route('deployments.show', $convert_deploymentUuid)" wire:navigate variant="tertiary">
                                {{ __('View deployment') }}
                            </x-button-link>
                        @endif
                    </div>
                </x-alert>
            @endif

            <x-card>
                <x-table loading-except="label, host, expires_at, never, deletingId, convert_school_name, convert_product, convertingId">
                    <x-table.head>
                        <x-table.row :hover="false">
                            <x-table.heading>Key</x-table.heading>
                            <x-table.heading>Expires</x-table.heading>
                            <x-table.heading>Host</x-table.heading>
                            <x-table.heading>Last used</x-table.heading>
                            <x-table.heading>Status</x-table.heading>
                            <x-table.heading><span class="sr-only">Actions</span></x-table.heading>
                        </x-table.row>
                    </x-table.head>
                    @if ($this->demoKeys->isNotEmpty())
                        <x-table.body>
                            @foreach ($this->demoKeys as $key)
                                <x-table.row>
                                    <x-table.cell>
                                        <div class="font-medium text-slate-900 dark:text-white">{{ $key->label }}</div>
                                        <div class="font-mono text-xs text-slate-400 dark:text-slate-500">{{ substr($key->code_hash, 0, 10) }}…</div>
                                    </x-table.cell>
                                    <x-table.cell>{{ $key->expires_at?->format('M d, Y H:i') ?? 'Never' }}</x-table.cell>
                                    <x-table.cell>{{ $key->host ?? '—' }}</x-table.cell>
                                    <x-table.cell>{{ $key->last_used_at?->diffForHumans() ?? '—' }}</x-table.cell>
                                    <x-table.cell>
                                        @if ($key->isConverted())
                                            <x-badge tone="success">Converted</x-badge>
                                        @else
                                            <x-badge :tone="$key->isRevoked() ? 'danger' : ($key->isExpired() ? 'warn' : 'success')">{{ $key->isRevoked() ? 'Revoked' : ($key->isExpired() ? 'Expired' : 'Active') }}</x-badge>
                                        @endif
                                    </x-table.cell>
                                    <x-table.cell>
                                        <span class="flex items-center gap-2">
                                            @if ($key->isConverted() && $key->convertedDeployment !== null)
                                                <x-button-link :href="route('deployments.show', $key->convertedDeployment)" wire:navigate variant="tertiary">
                                                    {{ __('View deployment') }}
                                                </x-button-link>
                                            @else
                                                <x-tertiary-button type="button" wire:click="openConvertModal({{ $key->id }})">
                                                    {{ __('Convert to live') }}
                                                </x-tertiary-button>
                                            @endif
                                            @if (! $this->signingKeyMissing)
                                                <x-button-link :href="route('demo-keys.download', $key)" variant="tertiary">
                                                    {{ __('Download') }}
                                                </x-button-link>
                                            @endif
                                            @if (! $key->isRevoked())
                                                <button type="button" x-data="" x-on:click.prevent="$dispatch('open-modal', 'demo-delete'); $wire.set('deletingId', {{ $key->id }})" class="text-sm font-medium text-red-500 underline hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                                                    {{ __('Revoke') }}
                                                </button>
                                            @endif
                                        </span>
                                    </x-table.cell>
                                </x-table.row>
                            @endforeach
                        </x-table.body>
                    @else
                        <x-table.empty>
                            <x-empty-state title="No demo keys yet" message="Mint the first key for a prospect or the marketing site." />
                        </x-table.empty>
                    @endif
                </x-table>

                <div class="mt-4">
                    {{ $this->demoKeys->links() }}
                </div>
            </x-card>

            <x-modal name="demo-form" focusable>
                <form wire:submit="mint" class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        {{ __('Mint demo key') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('The code is shown once and never stored — only its hash is retained.') }}
                    </p>
                    <div class="mt-6 flex flex-col gap-4">
                        <div>
                            <x-input-label for="label" :value="__('Label')" />
                            <x-text-input wire:model="label" id="label" class="mt-1 block w-full" type="text" name="label" required maxlength="255" placeholder="Prospect name or site" />
                            <x-input-error :messages="$errors->get('label')" class="mt-2" />
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <x-input-label for="expires_at" :value="__('Expires on')" />
                                <x-text-input wire:model="expires_at" id="expires_at" class="mt-1 block w-full" type="datetime-local" name="expires_at" :disabled="$never" />
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Defaults to 30 minutes out. Clear it only for never-expiring keys.</p>
                                <x-input-error :messages="$errors->get('expires_at')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="host" :value="__('Host lock (optional)')" />
                                <x-text-input wire:model="host" id="host" class="mt-1 block w-full font-mono" type="text" name="host" maxlength="255" placeholder="demo.example.com" />
                                <x-input-error :messages="$errors->get('host')" class="mt-2" />
                            </div>
                        </div>
                        <label class="flex cursor-pointer items-center gap-3">
                            <input wire:model.live="never" type="checkbox" class="rounded border-slate-300 dark:border-white/20 dark:bg-ink text-brand shadow-sm focus:ring-brand dark:focus:ring-accent dark:focus:ring-offset-deep" />
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium text-slate-700 dark:text-slate-200">{{ __('Never expires') }}</span>
                                <span class="block text-sm text-slate-500 dark:text-slate-400">{{ __('Asks for explicit confirmation before minting.') }}</span>
                            </span>
                        </label>
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <x-tertiary-button type="button" wire:click="cancelMint" x-on:click="$dispatch('close')">
                            {{ __('Cancel') }}
                        </x-tertiary-button>
                        @if ($this->signingKeyMissing)
                            <x-primary-button type="button" disabled>
                                <span>{{ __('Mint key') }}</span>
                            </x-primary-button>
                        @else
                            <x-primary-button wire:loading.attr="disabled" wire:target="mint">
                                <span wire:loading.remove wire:target="mint">{{ __('Mint key') }}</span>
                                <span wire:loading wire:target="mint">{{ __('Minting…') }}</span>
                            </x-primary-button>
                        @endif
                    </div>
                    <x-input-error :messages="$errors->get('signing_key')" class="mt-2" />
                </form>
            </x-modal>

            <x-modal name="demo-never-confirm" focusable>
                <form wire:submit="mintConfirmed" class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        {{ __('Mint a key that never expires?') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('This key outlives every rotation around it. Prefer a dated expiry unless the key serves standing infrastructure.') }}
                    </p>
                    <div class="mt-6 flex justify-end">
                        <x-secondary-button type="button" wire:click="cancelNeverConfirm">
                            {{ __('Cancel') }}
                        </x-secondary-button>
                        <x-danger-button class="ms-3" wire:loading.attr="disabled" wire:target="mintConfirmed">
                            <span wire:loading.remove wire:target="mintConfirmed">{{ __('Mint never-expiring key') }}</span>
                            <span wire:loading wire:target="mintConfirmed">{{ __('Minting…') }}</span>
                        </x-danger-button>
                    </div>
                </form>
            </x-modal>

            <x-modal name="demo-delete" focusable>
                <form wire:submit="delete" class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        {{ __('Revoke this demo key?') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('Offline copies keep verifying until they lapse — revocation bites on the next online check. This cannot be undone.') }}
                    </p>
                    <div class="mt-6 flex justify-end">
                        <x-secondary-button type="button" wire:click="cancelDelete">
                            {{ __('Cancel') }}
                        </x-secondary-button>
                        <x-danger-button class="ms-3" wire:loading.attr="disabled" wire:target="delete">
                            <span wire:loading.remove wire:target="delete">{{ __('Revoke key') }}</span>
                            <span wire:loading wire:target="delete">{{ __('Revoking…') }}</span>
                        </x-danger-button>
                    </div>
                </form>
            </x-modal>

            <x-modal name="demo-convert" focusable>
                <form wire:submit="convert" class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        {{ __('Convert to live deployment?') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('Registers the school, mints its first enrollment code, and retires this demo key — all at once.') }}
                    </p>
                    <div class="mt-6 flex flex-col gap-4">
                        <div>
                            <x-input-label for="convert_school_name" :value="__('School name')" />
                            <x-text-input wire:model="convert_school_name" id="convert_school_name" class="mt-1 block w-full" type="text" name="convert_school_name" required maxlength="255" />
                            <x-input-error :messages="$errors->get('convert_school_name')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="convert_product" :value="__('Product')" />
                            <x-select wire:model="convert_product" id="convert_product" name="convert_product" required class="mt-1 block w-full">
                                @foreach ($this->products as $productOption)
                                    <option value="{{ $productOption->slug }}">{{ $productOption->name }}{{ $productOption->active ? '' : ' (inactive)' }}</option>
                                @endforeach
                            </x-select>
                            <x-input-error :messages="$errors->get('convert_product')" class="mt-2" />
                        </div>
                    </div>
                    <x-input-error :messages="$errors->get('convert')" class="mt-2" />
                    <div class="mt-6 flex justify-end gap-2">
                        <x-tertiary-button type="button" wire:click="cancelConvert" x-on:click="$dispatch('close')">
                            {{ __('Cancel') }}
                        </x-tertiary-button>
                        <x-primary-button wire:loading.attr="disabled" wire:target="convert">
                            <span wire:loading.remove wire:target="convert">{{ __('Convert to live') }}</span>
                            <span wire:loading wire:target="convert">{{ __('Converting…') }}</span>
                        </x-primary-button>
                    </div>
                </form>
            </x-modal>
        </div>
    </div>
</div>
