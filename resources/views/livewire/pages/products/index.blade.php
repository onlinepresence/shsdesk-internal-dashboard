<?php

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public string $name = '';

    public string $slug = '';

    public ?int $keyProductId = null;

    public ?string $plainTextKey = null;

    #[Computed]
    public function products(): LengthAwarePaginator
    {
        return Product::query()
            ->withCount(['deployments', 'licences', 'openCodes'])
            ->orderBy('name')
            ->paginate(10);
    }

    /**
     * Product staged in the API key modal, if any.
     */
    #[Computed]
    public function keyProduct(): ?Product
    {
        if ($this->keyProductId === null) {
            return null;
        }

        return Product::find($this->keyProductId);
    }

    /**
     * Register a product. The slug is immutable once created.
     */
    public function create(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:64', 'alpha_dash:ascii', 'unique:products,slug'],
        ]);

        $product = Product::query()->create([
            'name' => $validated['name'],
            'slug' => strtolower($validated['slug']),
            'active' => true,
        ]);

        activity('products')
            ->performedOn($product)
            ->causedBy(Auth::user())
            ->log('product.created');

        $this->reset(['name', 'slug']);
        $this->dispatch('close-product-form');

        session()->flash('status', __('Product registered.'));
    }

    public function cancelCreate(): void
    {
        $this->reset(['name', 'slug']);
        $this->resetValidation();
        $this->dispatch('close-product-form');
    }

    /**
     * Stage a product in the API key modal.
     */
    public function openKeyModal(int $id): void
    {
        $this->keyProductId = Product::findOrFail($id)->id;
        $this->plainTextKey = null;
        $this->resetValidation();
        $this->dispatch('open-product-key');
    }

    public function cancelKeyModal(): void
    {
        $this->reset(['keyProductId', 'plainTextKey']);
        $this->resetValidation();
        $this->dispatch('close-product-key');
    }

    /**
     * Issue (or rotate) the bearer key. The hash is stored for future
     * verification and the ciphertext for the secured display — the
     * plaintext itself is shown once and never retained.
     */
    public function issueKey(): void
    {
        $product = Product::findOrFail($this->keyProductId);
        $rotated = $product->hasApiKey();
        $key = Product::generateApiKey();

        $product->update([
            'api_key_hash' => Product::hashApiKey($key),
            'api_key_encrypted' => encrypt($key),
        ]);

        activity('products')
            ->performedOn($product)
            ->causedBy(Auth::user())
            ->log($rotated ? 'product.key_rotated' : 'product.key_issued');

        $this->plainTextKey = $key;
    }

    /**
     * Revoke the bearer key. The product authenticates nothing
     * keyless until a fresh key is issued.
     */
    public function revokeKey(): void
    {
        $product = Product::findOrFail($this->keyProductId);

        $product->update(['api_key_hash' => null, 'api_key_encrypted' => null]);

        activity('products')
            ->performedOn($product)
            ->causedBy(Auth::user())
            ->log('product.key_revoked');

        $this->plainTextKey = null;
    }

    /**
     * Flip a product between active and inactive. Inactive products
     * authenticate nothing until activated.
     */
    public function toggleActive(int $id): void
    {
        $product = Product::findOrFail($id);

        $product->update(['active' => ! $product->active]);

        activity('products')
            ->performedOn($product)
            ->causedBy(Auth::user())
            ->withProperties(['active' => $product->active])
            ->log($product->active ? 'product.activated' : 'product.deactivated');
    }
}; ?>

<div class="py-12" x-data="{}" x-on:open-product-form.window="$dispatch('open-modal', 'product-form')" x-on:close-product-form.window="$dispatch('close-modal', 'product-form')" x-on:open-product-key.window="$dispatch('open-modal', 'product-key')" x-on:close-product-key.window="$dispatch('close-modal', 'product-key')">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="mb-6 flex flex-col gap-6">
            <x-section-title title="Products" subtitle="Product registry heartbeat and enrollment claims resolve against.">
                <x-primary-button type="button" wire:click="$dispatch('open-modal', 'product-form')">
                    {{ __('New product') }}
                </x-primary-button>
            </x-section-title>

            <x-alert flash="status" tone="success" />

            <x-card>
                <x-table>
                    <x-table.head>
                        <x-table.row :hover="false">
                            <x-table.heading>Product</x-table.heading>
                            <x-table.heading>Status</x-table.heading>
                            <x-table.heading>Deployments</x-table.heading>
                            <x-table.heading>Leads</x-table.heading>
                            <x-table.heading>Licences</x-table.heading>
                            <x-table.heading>API key</x-table.heading>
                            <x-table.heading><span class="sr-only">Actions</span></x-table.heading>
                        </x-table.row>
                    </x-table.head>
                    @if ($this->products->isNotEmpty())
                        <x-table.body>
                            @foreach ($this->products as $product)
                                <x-table.row>
                                    <x-table.cell>
                                        <div class="font-medium text-slate-900 dark:text-white">{{ $product->name }}</div>
                                        <div class="font-mono text-xs text-slate-400 dark:text-slate-500">{{ $product->slug }}</div>
                                    </x-table.cell>
                                    <x-table.cell>
                                        <x-badge :tone="$product->active ? 'success' : 'muted'">{{ $product->active ? 'Active' : 'Inactive' }}</x-badge>
                                    </x-table.cell>
                                    <x-table.cell>{{ $product->deployments_count }}</x-table.cell>
                                    <x-table.cell>{{ $product->open_codes_count }}</x-table.cell>
                                    <x-table.cell>{{ $product->licences_count }}</x-table.cell>
                                    <x-table.cell>
                                        <x-badge :tone="$product->hasApiKey() ? 'active' : 'muted'">{{ $product->hasApiKey() ? 'Issued' : 'None' }}</x-badge>
                                    </x-table.cell>
                                    <x-table.cell>
                                        <span class="flex items-center gap-2">
                                            <x-tertiary-button type="button" wire:click="openKeyModal({{ $product->id }})">
                                                {{ __('API key') }}
                                            </x-tertiary-button>
                                            <x-tertiary-button type="button" wire:click="toggleActive({{ $product->id }})">
                                                {{ $product->active ? __('Deactivate') : __('Activate') }}
                                            </x-tertiary-button>
                                        </span>
                                    </x-table.cell>
                                </x-table.row>
                            @endforeach
                        </x-table.body>
                    @else
                        <x-table.empty>
                            <x-empty-state title="No products yet" message="Register the first product before enrolling deployments." />
                        </x-table.empty>
                    @endif
                </x-table>

                <div class="mt-4">
                    {{ $this->products->links() }}
                </div>
            </x-card>

            <x-modal name="product-form" focusable>
                <form wire:submit="create" class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        {{ __('New product') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('The slug is immutable once created — deployments reference it forever.') }}
                    </p>
                    <div class="mt-6 flex flex-col gap-4">
                        <div>
                            <x-input-label for="name" :value="__('Name')" />
                            <x-text-input wire:model="name" id="name" class="mt-1 block w-full" type="text" name="name" required maxlength="255" />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="slug" :value="__('Slug')" />
                            <x-text-input wire:model="slug" id="slug" class="mt-1 block w-full font-mono" type="text" name="slug" required maxlength="64" placeholder="myproduct" />
                            <x-input-error :messages="$errors->get('slug')" class="mt-2" />
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <x-tertiary-button type="button" wire:click="cancelCreate" x-on:click="$dispatch('close')">
                            {{ __('Cancel') }}
                        </x-tertiary-button>
                        <x-primary-button wire:loading.attr="disabled" wire:target="create">
                            <span wire:loading.remove wire:target="create">{{ __('Create product') }}</span>
                            <span wire:loading wire:target="create">{{ __('Creating…') }}</span>
                        </x-primary-button>
                    </div>
                </form>
            </x-modal>

            <x-modal name="product-key" focusable>
                <div class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        {{ __('API key') }} — {{ $this->keyProduct?->name ?? '…' }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('Bearer key for this product. Rotating invalidates the old key immediately.') }}
                    </p>
                    @if ($this->keyProduct?->hasApiKey() && $this->keyProduct?->revealApiKey() !== null)
                        <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Current key</p>
                            <div x-data="{ copiedKey: false }" class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center">
                                <code class="min-w-0 flex-1 break-all font-mono text-sm text-slate-900 dark:text-white">{{ $this->keyProduct->maskedApiKey() }}</code>
                                <input type="hidden" x-ref="currentKey" value="{{ $this->keyProduct->revealApiKey() }}" />
                                <x-secondary-button type="button" @click="navigator.clipboard.writeText($refs.currentKey.value); copiedKey = true" x-text="copiedKey ? 'Copied' : 'Copy key'" />
                            </div>
                        </div>
                    @elseif ($this->keyProduct?->hasApiKey())
                        <p class="mt-4 text-sm text-amber-700 dark:text-amber-400">The stored key can no longer be decrypted (app key changed). Rotate to issue a readable one.</p>
                    @endif
                    @if ($plainTextKey !== null)
                        <x-alert tone="warn" title="New key issued — copy it now" :dismissible="false" class="mt-4">
                            <div x-data="{ copied: false }" class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center">
                                <code x-ref="key" class="min-w-0 flex-1 break-all font-mono text-sm">{{ $plainTextKey }}</code>
                                <x-secondary-button type="button" @click="navigator.clipboard.writeText($refs.key.innerText.trim()); copied = true" x-text="copied ? 'Copied' : 'Copy'" />
                            </div>
                        </x-alert>
                    @endif
                    <div class="mt-6 flex justify-end gap-2">
                        <x-tertiary-button type="button" wire:click="cancelKeyModal" x-on:click="$dispatch('close')">
                            {{ __('Close') }}
                        </x-tertiary-button>
                        @if ($this->keyProduct?->hasApiKey())
                            <x-danger-button type="button" wire:click="revokeKey" wire:loading.attr="disabled" wire:target="revokeKey">
                                <span wire:loading.remove wire:target="revokeKey">{{ __('Revoke') }}</span>
                                <span wire:loading wire:target="revokeKey">{{ __('Revoking…') }}</span>
                            </x-danger-button>
                        @endif
                        <x-primary-button type="button" wire:click="issueKey" wire:loading.attr="disabled" wire:target="issueKey">
                            <span wire:loading.remove wire:target="issueKey">{{ $this->keyProduct?->hasApiKey() ? __('Rotate key') : __('Issue key') }}</span>
                            <span wire:loading wire:target="issueKey">{{ __('Issuing…') }}</span>
                        </x-primary-button>
                    </div>
                </div>
            </x-modal>
        </div>
    </div>
</div>
