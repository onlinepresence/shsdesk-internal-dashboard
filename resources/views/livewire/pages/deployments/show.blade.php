<?php

use App\Models\Deployment;
use App\Models\EnrollmentCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public Deployment $deployment;

    public ?string $plainTextToken = null;

    public ?string $plainTextCode = null;

    public function mount(Deployment $deployment): void
    {
        $this->deployment = $deployment;
    }

    #[Computed]
    public function hasToken(): bool
    {
        return $this->deployment->tokens()->exists();
    }

    #[Computed]
    public function heartbeats(): Collection
    {
        return $this->deployment->heartbeats()->limit(15)->get();
    }

    /**
     * Newest still-redeemable claim code, if one is open.
     */
    #[Computed]
    public function activeCode(): ?EnrollmentCode
    {
        return $this->deployment->enrollmentCodes()
            ->whereNull('voided_at')
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();
    }

    public function statusTone(string $status): string
    {
        return match ($status) {
            'active' => 'active',
            'stale' => 'warn',
            'revoked' => 'danger',
            default => 'muted',
        };
    }

    /**
     * Replace every token with a fresh heartbeat token.
     */
    public function regenerateToken(): void
    {
        $this->deployment->tokens()->delete();

        $token = $this->deployment->createToken('heartbeat', [Deployment::HEARTBEAT_ABILITY]);

        activity('deployments')
            ->performedOn($this->deployment)
            ->causedBy(Auth::user())
            ->log('deployment.token_regenerated');

        $this->plainTextToken = $token->plainTextToken;

        $this->dispatch('close-modal', 'confirm-token-regenerate');
    }

    /**
     * Revoke access and retire every token.
     */
    public function revoke(): void
    {
        $this->deployment->update(['revoked_at' => now()]);
        $this->deployment->tokens()->delete();

        activity('deployments')
            ->performedOn($this->deployment)
            ->causedBy(Auth::user())
            ->log('deployment.revoked');

        $this->dispatch('close-modal', 'confirm-deployment-revoke');
    }

    /**
     * Mint a claim code bound to this deployment. The plaintext is
     * shown once and never stored — only its hash is retained.
     */
    public function mintCode(): void
    {
        if ($this->activeCode !== null) {
            $this->addError('code', __('A usable code is already open. Regenerate or void it first.'));

            return;
        }

        $minted = EnrollmentCode::mintFor($this->deployment);

        activity('enrollment')
            ->performedOn($minted['record'])
            ->causedBy(Auth::user())
            ->withProperties(['expires_at' => $minted['record']->expires_at?->toIso8601String()])
            ->log('enrollment.code_minted');

        unset($this->activeCode);

        $this->plainTextCode = $minted['code'];
    }

    /**
     * Retire the open code and mint a fresh one in its place.
     */
    public function regenerateCode(): void
    {
        $this->deployment->enrollmentCodes()
            ->whereNull('voided_at')
            ->whereNull('consumed_at')
            ->update(['voided_at' => now()]);

        $minted = EnrollmentCode::mintFor($this->deployment);

        activity('enrollment')
            ->performedOn($minted['record'])
            ->causedBy(Auth::user())
            ->withProperties(['expires_at' => $minted['record']->expires_at?->toIso8601String()])
            ->log('enrollment.code_regenerated');

        unset($this->activeCode);

        $this->plainTextCode = $minted['code'];

        $this->dispatch('close-modal', 'confirm-code-regenerate');
    }

    /**
     * Retire the open code without a replacement.
     */
    public function voidCode(): void
    {
        $this->deployment->enrollmentCodes()
            ->whereNull('voided_at')
            ->whereNull('consumed_at')
            ->update(['voided_at' => now()]);

        activity('enrollment')
            ->performedOn($this->deployment)
            ->causedBy(Auth::user())
            ->log('enrollment.code_voided');

        unset($this->activeCode);

        $this->plainTextCode = null;

        $this->dispatch('close-modal', 'confirm-code-void');
    }
}; ?>

<div class="py-12">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="mb-6 flex flex-col gap-6">
            <x-section-title :title="$deployment->school_name" subtitle="Deployment detail.">
                <x-button-link :href="route('licences.edit', $deployment->uuid)" wire:navigate variant="tertiary">
                    {{ __('Manage licence') }}
                </x-button-link>
                <a href="{{ route('deployments.index') }}" wire:navigate class="text-sm font-medium text-brand underline hover:text-deep dark:text-slate-200 dark:hover:text-white">
                    {{ __('Back to deployments') }}
                </a>
            </x-section-title>

            @if ($plainTextToken !== null)
                <x-alert tone="warn" title="New token issued — copy it now" :dismissible="false">
                    <div x-data="{ copied: false }" class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center">
                        <code x-ref="token" class="min-w-0 flex-1 break-all font-mono text-sm">{{ $plainTextToken }}</code>
                        <x-secondary-button type="button" @click="navigator.clipboard.writeText($refs.token.innerText.trim()); copied = true" x-text="copied ? 'Copied' : 'Copy'" />
                    </div>
                </x-alert>
            @endif

            <x-card>
                <x-slot name="title">Instance</x-slot>
                <x-slot name="actions">
                    <x-badge :tone="$this->statusTone($deployment->status)">{{ ucfirst($deployment->status) }}</x-badge>
                </x-slot>
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">UUID</dt>
                        <dd class="mt-1 break-all font-mono text-sm text-slate-900 dark:text-white">{{ $deployment->uuid }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Product</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $deployment->product }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">URL</dt>
                        <dd class="mt-1 truncate text-sm text-slate-900 dark:text-white">{{ $deployment->url }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Version</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $deployment->app_version ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Last seen</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $deployment->last_seen_at?->diffForHumans() ?? 'Never' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Registered</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $deployment->created_at->diffForHumans() }}</dd>
                    </div>
                </dl>
            </x-card>

            <x-card>
                <x-slot name="title">Heartbeat token</x-slot>
                @if ($deployment->revoked_at !== null)
                    <x-alert tone="danger" title="Revoked" :dismissible="false">
                        This deployment was revoked {{ $deployment->revoked_at->diffForHumans() }}. Its tokens no longer authenticate.
                    </x-alert>
                @else
                    <div class="flex flex-col gap-3">
                        <p class="text-sm text-slate-600 dark:text-slate-300">
                            {{ $this->hasToken ? 'A token is issued for this deployment. Only a fresh token value is ever shown.' : 'No active token. Regenerate to issue one.' }}
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <x-secondary-button type="button" x-data="" x-on:click.prevent="$dispatch('open-modal', 'confirm-token-regenerate')">
                                {{ $this->hasToken ? __('Regenerate token') : __('Issue token') }}
                            </x-secondary-button>
                            <x-danger-button type="button" x-data="" x-on:click.prevent="$dispatch('open-modal', 'confirm-deployment-revoke')">
                                {{ __('Revoke deployment') }}
                            </x-danger-button>
                        </div>
                    </div>
                @endif
            </x-card>

            <x-card>
                <x-slot name="title">Enrollment codes</x-slot>
                <div class="flex flex-col gap-3">
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        Claim codes enroll a fresh install once, then burn. Reinstalls reuse the heartbeat token above — there is no second path here.
                    </p>
                    @if ($plainTextCode !== null)
                        <x-alert tone="warn" title="New code minted — copy it now" :dismissible="false">
                            <div x-data="{ copied: false }" class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center">
                                <code x-ref="code" class="min-w-0 flex-1 break-all font-mono text-sm">{{ $plainTextCode }}</code>
                                <x-secondary-button type="button" @click="navigator.clipboard.writeText($refs.code.innerText.trim()); copied = true" x-text="copied ? 'Copied' : 'Copy'" />
                            </div>
                        </x-alert>
                    @endif
                    @if ($this->activeCode !== null)
                        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Expires</dt>
                                <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $this->activeCode->expires_at->diffForHumans() }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Failed presentations</dt>
                                <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $this->activeCode->failed_attempts }} / {{ EnrollmentCode::MAX_ATTEMPTS }}</dd>
                            </div>
                        </dl>
                        <div class="flex flex-wrap gap-2">
                            <x-secondary-button type="button" x-data="" x-on:click.prevent="$dispatch('open-modal', 'confirm-code-regenerate')">
                                {{ __('Regenerate code') }}
                            </x-secondary-button>
                            <x-danger-button type="button" x-data="" x-on:click.prevent="$dispatch('open-modal', 'confirm-code-void')">
                                {{ __('Void code') }}
                            </x-danger-button>
                        </div>
                    @else
                        <div class="flex flex-wrap gap-2">
                            <x-secondary-button type="button" wire:click="mintCode" wire:loading.attr="disabled" wire:target="mintCode">
                                <span wire:loading.remove wire:target="mintCode">{{ __('Mint code') }}</span>
                                <span wire:loading wire:target="mintCode">{{ __('Minting…') }}</span>
                            </x-secondary-button>
                        </div>
                    @endif
                    <x-input-error :messages="$errors->get('code')" class="mt-1" />
                </div>
            </x-card>

            <x-card>
                <x-slot name="title">Heartbeat history</x-slot>
                <x-table>
                    <x-table.head>
                        <x-table.row :hover="false">
                            <x-table.heading>Received</x-table.heading>
                            <x-table.heading>Version</x-table.heading>
                            <x-table.heading>Students</x-table.heading>
                            <x-table.heading>Teachers</x-table.heading>
                            <x-table.heading>Users</x-table.heading>
                            <x-table.heading>Modules</x-table.heading>
                        </x-table.row>
                    </x-table.head>
                    @if ($this->heartbeats->isNotEmpty())
                        <x-table.body>
                            @foreach ($this->heartbeats as $heartbeat)
                                <x-table.row>
                                    <x-table.cell>{{ $heartbeat->created_at->diffForHumans() }}</x-table.cell>
                                    <x-table.cell>{{ $heartbeat->app_version ?? '—' }}</x-table.cell>
                                    <x-table.cell>{{ $heartbeat->students }}</x-table.cell>
                                    <x-table.cell>{{ $heartbeat->teachers }}</x-table.cell>
                                    <x-table.cell>{{ $heartbeat->users }}</x-table.cell>
                                    <x-table.cell>{{ count($heartbeat->modules_in_use ?? []) }}</x-table.cell>
                                </x-table.row>
                            @endforeach
                        </x-table.body>
                    @else
                        <x-table.empty>
                            <x-empty-state title="No heartbeats yet" message="Receipts appear here once the instance checks in." />
                        </x-table.empty>
                    @endif
                </x-table>
            </x-card>

            <x-modal name="confirm-token-regenerate" focusable>
                <form wire:submit="regenerateToken" class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        {{ __('Issue a fresh token?') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('Every existing token for this deployment stops working immediately. The new value is shown once.') }}
                    </p>
                    <div class="mt-6 flex justify-end">
                        <x-secondary-button x-on:click="$dispatch('close')">
                            {{ __('Cancel') }}
                        </x-secondary-button>
                        <x-primary-button class="ms-3" wire:loading.attr="disabled" wire:target="regenerateToken">
                            <span wire:loading.remove wire:target="regenerateToken">{{ __('Regenerate') }}</span>
                            <span wire:loading wire:target="regenerateToken">{{ __('Regenerating…') }}</span>
                        </x-primary-button>
                    </div>
                </form>
            </x-modal>

            <x-modal name="confirm-deployment-revoke" focusable>
                <form wire:submit="revoke" class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        {{ __('Revoke this deployment?') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('Its tokens stop working immediately and heartbeats are rejected. This cannot be undone from here.') }}
                    </p>
                    <div class="mt-6 flex justify-end">
                        <x-secondary-button x-on:click="$dispatch('close')">
                            {{ __('Cancel') }}
                        </x-secondary-button>
                        <x-danger-button class="ms-3" wire:loading.attr="disabled" wire:target="revoke">
                            <span wire:loading.remove wire:target="revoke">{{ __('Revoke deployment') }}</span>
                            <span wire:loading wire:target="revoke">{{ __('Revoking…') }}</span>
                        </x-danger-button>
                    </div>
                </form>
            </x-modal>

            <x-modal name="confirm-code-regenerate" focusable>
                <form wire:submit="regenerateCode" class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        {{ __('Regenerate the claim code?') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('The open code stops working immediately and a fresh one is shown once.') }}
                    </p>
                    <div class="mt-6 flex justify-end">
                        <x-secondary-button x-on:click="$dispatch('close')">
                            {{ __('Cancel') }}
                        </x-secondary-button>
                        <x-primary-button class="ms-3" wire:loading.attr="disabled" wire:target="regenerateCode">
                            <span wire:loading.remove wire:target="regenerateCode">{{ __('Regenerate') }}</span>
                            <span wire:loading wire:target="regenerateCode">{{ __('Regenerating…') }}</span>
                        </x-primary-button>
                    </div>
                </form>
            </x-modal>

            <x-modal name="confirm-code-void" focusable>
                <form wire:submit="voidCode" class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        {{ __('Void the claim code?') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('The open code stops working immediately. Mint a new one whenever the install is ready.') }}
                    </p>
                    <div class="mt-6 flex justify-end">
                        <x-secondary-button x-on:click="$dispatch('close')">
                            {{ __('Cancel') }}
                        </x-secondary-button>
                        <x-danger-button class="ms-3" wire:loading.attr="disabled" wire:target="voidCode">
                            <span wire:loading.remove wire:target="voidCode">{{ __('Void code') }}</span>
                            <span wire:loading wire:target="voidCode">{{ __('Voiding…') }}</span>
                        </x-danger-button>
                    </div>
                </form>
            </x-modal>
        </div>
    </div>
</div>
