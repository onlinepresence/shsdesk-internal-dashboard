<?php

use App\Models\Deployment;
use App\Models\Lead;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $school_name = '';

    public string $product = 'flowedu';

    public string $url = '';

    public ?string $plainTextToken = null;

    public ?Deployment $createdDeployment = null;

    /**
     * Pre-fill from a converted lead without changing the form.
     */
    public function mount(): void
    {
        $this->authorize('ops.access');

        $prefill = session('lead_prefill');

        if (! is_array($prefill)) {
            return;
        }

        $this->school_name = (string) ($prefill['school_name'] ?? '');

        if (isset($prefill['product'])) {
            $this->product = (string) $prefill['product'];
        }
    }

    /**
     * Products a deployment may register under, live from the table.
     *
     * @return Collection<int, Product>
     */
    #[Computed]
    public function products(): Collection
    {
        return Product::orderBy('name')->get();
    }

    /**
     * Register a deployment and issue its first heartbeat token.
     */
    public function register(): void
    {
        $this->authorize('ops.access');

        $validated = $this->validate([
            'school_name' => ['required', 'string', 'max:255'],
            'product' => ['required', 'string', 'exists:products,slug'],
            'url' => ['required', 'url', 'max:2048'],
        ]);

        $deployment = Deployment::create($validated);

        $token = $deployment->createToken('heartbeat', [Deployment::HEARTBEAT_ABILITY]);

        if (session()->has('lead_prefill')) {
            $prefill = session('lead_prefill');

            session([
                'licence_prefill_'.$deployment->id => [
                    'modules' => $prefill['modules'] ?? [],
                    'band' => $prefill['band'] ?? null,
                    'notes' => $prefill['notes'] ?? null,
                ],
            ]);
            session()->forget('lead_prefill');

            if (isset($prefill['lead_id'])) {
                Lead::whereKey($prefill['lead_id'])->update(['status' => Lead::STATUS_CONVERTED]);

                activity('leads')
                    ->causedBy(Auth::user())
                    ->withProperties(['deployment_id' => $deployment->id])
                    ->log('lead.converted');
            }
        }

        activity('deployments')
            ->performedOn($deployment)
            ->causedBy(Auth::user())
            ->withProperties(['product' => $deployment->product])
            ->log('deployment.registered');

        $this->createdDeployment = $deployment;
        $this->plainTextToken = $token->plainTextToken;
    }
}; ?>

<div class="py-12">
    <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
        <div class="mb-6">
            <x-section-title title="Register deployment" subtitle="Issue a heartbeat token for a school product instance." />
        </div>

        @if ($plainTextToken !== null && $createdDeployment !== null)
            <x-card>
                <x-slot name="title">Token issued</x-slot>
                <div class="flex flex-col gap-4">
                    <x-alert tone="warn" title="Copy this token now" :dismissible="false">
                        It is shown once and cannot be recovered. Store it in the school product configuration.
                    </x-alert>
                    <div x-data="{ copied: false }" class="flex flex-col gap-2 rounded-md border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5 sm:flex-row sm:items-center">
                        <code x-ref="token" class="min-w-0 flex-1 break-all font-mono text-sm text-slate-900 dark:text-white">{{ $plainTextToken }}</code>
                        <x-secondary-button type="button" @click="navigator.clipboard.writeText($refs.token.innerText.trim()); copied = true" x-text="copied ? 'Copied' : 'Copy'" />
                    </div>
                    <div>
                        <a href="{{ route('deployments.show', $createdDeployment) }}" wire:navigate class="text-sm font-medium text-brand underline hover:text-deep dark:text-slate-200 dark:hover:text-white">
                            {{ __('View deployment') }}
                        </a>
                    </div>
                </div>
            </x-card>
        @else
            <x-card>
                <form wire:submit="register" class="flex flex-col gap-4">
                    <div>
                        <x-input-label for="school_name" :value="__('School name')" />
                        <x-text-input wire:model="school_name" id="school_name" class="mt-1 block w-full" type="text" name="school_name" required autofocus />
                        <x-input-error :messages="$errors->get('school_name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="product" :value="__('Product')" />
                        <x-select wire:model="product" id="product" name="product" required class="mt-1 block w-full">
                            @foreach ($this->products as $productOption)
                                <option value="{{ $productOption->slug }}">{{ $productOption->name }}{{ $productOption->active ? '' : ' (inactive)' }}</option>
                            @endforeach
                        </x-select>
                        <x-input-error :messages="$errors->get('product')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="url" :value="__('Instance URL')" />
                        <x-text-input wire:model="url" id="url" class="mt-1 block w-full" type="url" name="url" required placeholder="https://school.example.com" />
                        <x-input-error :messages="$errors->get('url')" class="mt-2" />
                    </div>

                    <div class="flex items-center justify-end gap-2">
                        <x-button-link :href="route('deployments.index')" wire:navigate variant="tertiary">
                            {{ __('Cancel') }}
                        </x-button-link>
                        <x-primary-button wire:loading.attr="disabled" wire:target="register">
                            <span wire:loading.remove wire:target="register">{{ __('Register') }}</span>
                            <span wire:loading wire:target="register">{{ __('Registering…') }}</span>
                        </x-primary-button>
                    </div>
                </form>
            </x-card>
        @endif
    </div>
</div>
