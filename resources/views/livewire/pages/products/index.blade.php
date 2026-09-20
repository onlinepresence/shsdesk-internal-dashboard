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

    #[Computed]
    public function products(): LengthAwarePaginator
    {
        return Product::query()
            ->withCount(['deployments', 'licences', 'openLeads'])
            ->orderBy('name')
            ->paginate(10);
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

<div class="py-12" x-data="{}" x-on:open-product-form.window="$dispatch('open-modal', 'product-form')" x-on:close-product-form.window="$dispatch('close-modal', 'product-form')">
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
                                    <x-table.cell>{{ $product->open_leads_count }}</x-table.cell>
                                    <x-table.cell>{{ $product->licences_count }}</x-table.cell>
                                    <x-table.cell>
                                        <x-tertiary-button type="button" wire:click="toggleActive({{ $product->id }})">
                                            {{ $product->active ? __('Deactivate') : __('Activate') }}
                                        </x-tertiary-button>
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
        </div>
    </div>
</div>
