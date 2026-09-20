<?php

use App\Models\DemoKey;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
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

    #[Computed]
    public function demoKeys(): LengthAwarePaginator
    {
        return DemoKey::query()->latest()->paginate(10);
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

<div class="py-12" x-data="{}" x-on:open-demo-form.window="$dispatch('open-modal', 'demo-form')" x-on:close-demo-form.window="$dispatch('close-modal', 'demo-form')" x-on:open-demo-never-confirm.window="$dispatch('open-modal', 'demo-never-confirm')" x-on:close-demo-never-confirm.window="$dispatch('close-modal', 'demo-never-confirm')" x-on:open-demo-delete.window="$dispatch('open-modal', 'demo-delete')" x-on:close-demo-delete.window="$dispatch('close-modal', 'demo-delete')">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="mb-6 flex flex-col gap-6">
            <x-section-title title="Demo keys" subtitle="Signed demo credentials. Validity and host binding live inside the signature — verifiers trust nothing else.">
                <x-primary-button type="button" wire:click="openMintModal">
                    {{ __('Mint key') }}
                </x-primary-button>
            </x-section-title>

            <x-alert flash="status" tone="success" />

            @if ($plainTextCode !== null)
                <x-alert tone="warn" title="New key minted — copy it now" :dismissible="false">
                    <div x-data="{ copied: false }" class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center">
                        <code x-ref="code" class="min-w-0 flex-1 break-all font-mono text-sm">{{ $plainTextCode }}</code>
                        <x-secondary-button type="button" @click="navigator.clipboard.writeText($refs.code.innerText.trim()); copied = true" x-text="copied ? 'Copied' : 'Copy'" />
                        @if ($mintedId !== null)
                            <x-button-link :href="route('demo-keys.download', $mintedId)" variant="tertiary">
                                {{ __('Download file') }}
                            </x-button-link>
                        @endif
                    </div>
                </x-alert>
            @endif

            <x-card>
                <x-table loading-except="label, host, expires_at, never, deletingId">
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
                                        <x-badge :tone="$key->isRevoked() ? 'danger' : ($key->isExpired() ? 'warn' : 'success')">{{ $key->isRevoked() ? 'Revoked' : ($key->isExpired() ? 'Expired' : 'Active') }}</x-badge>
                                    </x-table.cell>
                                    <x-table.cell>
                                        <span class="flex items-center gap-2">
                                            <x-button-link :href="route('demo-keys.download', $key)" variant="tertiary">
                                                {{ __('Download') }}
                                            </x-button-link>
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
                        <x-primary-button wire:loading.attr="disabled" wire:target="mint">
                            <span wire:loading.remove wire:target="mint">{{ __('Mint key') }}</span>
                            <span wire:loading wire:target="mint">{{ __('Minting…') }}</span>
                        </x-primary-button>
                    </div>
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
        </div>
    </div>
</div>
