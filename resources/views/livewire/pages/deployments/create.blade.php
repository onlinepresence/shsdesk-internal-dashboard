<?php

use App\Models\Deployment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
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
     * Register a deployment and issue its first heartbeat token.
     */
    public function register(): void
    {
        $validated = $this->validate([
            'school_name' => ['required', 'string', 'max:255'],
            'product' => ['required', 'string', Rule::in(Deployment::PRODUCTS)],
            'url' => ['required', 'url', 'max:2048'],
        ]);

        $deployment = Deployment::create($validated);

        $token = $deployment->createToken('heartbeat', [Deployment::HEARTBEAT_ABILITY]);

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
                        <select wire:model="product" id="product" name="product" required class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-brand focus:ring-brand dark:border-white/15 dark:bg-ink dark:text-slate-100 dark:focus:border-accent dark:focus:ring-accent">
                            @foreach (Deployment::PRODUCTS as $productOption)
                                <option value="{{ $productOption }}">{{ $productOption }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('product')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="url" :value="__('Instance URL')" />
                        <x-text-input wire:model="url" id="url" class="mt-1 block w-full" type="url" name="url" required placeholder="https://school.example.com" />
                        <x-input-error :messages="$errors->get('url')" class="mt-2" />
                    </div>

                    <div class="flex items-center justify-end gap-2">
                        <a href="{{ route('deployments.index') }}" wire:navigate class="inline-flex items-center px-4 py-2 text-xs font-semibold uppercase tracking-widest text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">
                            {{ __('Cancel') }}
                        </a>
                        <x-primary-button>{{ __('Register') }}</x-primary-button>
                    </div>
                </form>
            </x-card>
        @endif
    </div>
</div>
