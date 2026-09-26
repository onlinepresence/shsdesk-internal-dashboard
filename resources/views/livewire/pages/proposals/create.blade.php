<?php

use App\Models\Deployment;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Proposal;
use App\Models\ProposalTemplate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?int $product_id = null;

    public ?int $proposal_template_id = null;

    public string $school = '';

    public string $client = '';

    public string $contact = '';

    public string $band = '';

    public string $date = '';

    public ?int $deployment_id = null;

    public ?int $lead_id = null;

    /**
     * Pre-fill from a converted lead without changing the form.
     */
    public function mount(): void
    {
        $this->authorize('ops.access');

        $this->date = today()->toDateString();

        $prefill = session()->pull('proposal_prefill');

        if (! is_array($prefill)) {
            return;
        }

        $this->school = (string) ($prefill['school'] ?? '');
        $this->client = (string) ($prefill['client'] ?? '');
        $this->contact = (string) ($prefill['contact'] ?? '');
        $this->band = (string) ($prefill['band'] ?? '');
        $this->lead_id = isset($prefill['lead_id']) ? (int) $prefill['lead_id'] : null;

        if (isset($prefill['product_id'])) {
            $this->product_id = (int) $prefill['product_id'];
        }
    }

    /**
     * Products a proposal may be quoted under, live from the table.
     *
     * @return Collection<int, Product>
     */
    #[Computed]
    public function products(): Collection
    {
        return Product::orderBy('name')->get();
    }

    /**
     * Templates for the picked product. Picking the product first keeps
     * the list short; clearing it lists everything.
     *
     * @return Collection<int, ProposalTemplate>
     */
    #[Computed]
    public function templates(): Collection
    {
        return ProposalTemplate::query()
            ->with('product')
            ->when($this->product_id !== null, fn ($query) => $query->where('product_id', $this->product_id))
            ->orderBy('title')
            ->get();
    }

    /**
     * Deployments the proposal may attach to, newest first.
     *
     * @return Collection<int, Deployment>
     */
    #[Computed]
    public function deployments(): Collection
    {
        return Deployment::orderByDesc('id')->limit(100)->get(['id', 'school_name', 'uuid']);
    }

    /**
     * Freeze a proposal instance off the template and hand over to the
     * proposals list. Pricing, sections, and the proposal number are
     * snapshotted now — nothing later moves them.
     */
    public function generate(): void
    {
        $this->authorize('ops.access');

        $validated = $this->validate([
            'proposal_template_id' => ['required', 'integer', 'exists:proposal_templates,id'],
            'school' => ['required', 'string', 'max:255'],
            'client' => ['required', 'string', 'max:255'],
            'contact' => ['required', 'string', 'max:255'],
            'band' => ['nullable', 'string', 'max:64'],
            'date' => ['required', 'date'],
            'deployment_id' => ['nullable', 'integer', 'exists:deployments,id'],
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
        ]);

        $template = ProposalTemplate::with('sections')->findOrFail($validated['proposal_template_id']);

        $proposal = Proposal::generateFrom($template, [
            'school' => $validated['school'],
            'client' => $validated['client'],
            'contact' => $validated['contact'],
            'band' => $validated['band'] ?? '',
            'date' => $validated['date'],
        ], $validated['lead_id'], $validated['deployment_id']);

        activity('proposals')
            ->performedOn($proposal)
            ->causedBy(Auth::user())
            ->withProperties(['template_id' => $template->id, 'proposal_no' => $proposal->proposal_no])
            ->log('proposal.generated');

        session()->flash('status', __('Proposal :no generated.', ['no' => $proposal->proposal_no]));

        $this->redirect(route('proposals.index'), navigate: true);
    }
}; ?>

<div class="py-12">
    <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
        <div class="mb-6">
            <x-section-title title="Generate proposal" subtitle="Frozen off the template at generation — text, values, pricing, and number." />
        </div>

        <x-card>
            <form wire:submit="generate" class="flex flex-col gap-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="product_id" :value="__('Product')" />
                        <x-select wire:model.live="product_id" id="product_id" name="product_id" class="mt-1 block w-full">
                            <option value="">{{ __('All products') }}</option>
                            @foreach ($this->products as $product)
                                <option value="{{ $product->id }}">{{ $product->name }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <div>
                        <x-input-label for="proposal_template_id" :value="__('Template')" />
                        <x-select wire:model="proposal_template_id" id="proposal_template_id" name="proposal_template_id" required class="mt-1 block w-full">
                            <option value="">{{ __('Pick a template') }}</option>
                            @foreach ($this->templates as $template)
                                <option value="{{ $template->id }}">{{ $template->title }} (v{{ $template->version }})</option>
                            @endforeach
                        </x-select>
                        <x-input-error :messages="$errors->get('proposal_template_id')" class="mt-2" />
                    </div>
                </div>

                <div>
                    <x-input-label for="school" :value="__('School')" />
                    <x-text-input wire:model="school" id="school" class="mt-1 block w-full" type="text" name="school" required autofocus maxlength="255" />
                    <x-input-error :messages="$errors->get('school')" class="mt-2" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="client" :value="__('Client name')" />
                        <x-text-input wire:model="client" id="client" class="mt-1 block w-full" type="text" name="client" required maxlength="255" />
                        <x-input-error :messages="$errors->get('client')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="contact" :value="__('Contact')" />
                        <x-text-input wire:model="contact" id="contact" class="mt-1 block w-full" type="text" name="contact" required maxlength="255" placeholder="Email or phone" />
                        <x-input-error :messages="$errors->get('contact')" class="mt-2" />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="band" :value="__('Student band (optional)')" />
                        <x-text-input wire:model="band" id="band" class="mt-1 block w-full" type="text" name="band" maxlength="64" placeholder="1-500" />
                        <x-input-error :messages="$errors->get('band')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="date" :value="__('Proposal date')" />
                        <x-text-input wire:model="date" id="date" class="mt-1 block w-full" type="date" name="date" required />
                        <x-input-error :messages="$errors->get('date')" class="mt-2" />
                    </div>
                </div>

                <div>
                    <x-input-label for="deployment_id" :value="__('Deployment (optional)')" />
                    <x-select wire:model="deployment_id" id="deployment_id" name="deployment_id" class="mt-1 block w-full">
                        <option value="">{{ __('None') }}</option>
                        @foreach ($this->deployments as $deployment)
                            <option value="{{ $deployment->id }}">{{ $deployment->school_name }}</option>
                        @endforeach
                    </x-select>
                    <x-input-error :messages="$errors->get('deployment_id')" class="mt-2" />
                </div>

                <div class="flex items-center justify-end gap-2">
                    <x-button-link :href="route('proposals.index')" wire:navigate variant="tertiary">
                        {{ __('Cancel') }}
                    </x-button-link>
                    <x-primary-button wire:loading.attr="disabled" wire:target="generate">
                        <span wire:loading.remove wire:target="generate">{{ __('Generate proposal') }}</span>
                        <span wire:loading wire:target="generate">{{ __('Generating…') }}</span>
                    </x-primary-button>
                </div>
            </form>
        </x-card>
    </div>
</div>
