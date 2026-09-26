<?php

use App\Models\Product;
use App\Models\ProposalTemplate;
use App\Models\TemplateSection;
use App\Support\DocxImporter;
use App\Support\HtmlSanitizer;
use App\Support\ProposalPlaceholders;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;
    use WithPagination;

    public string $new_title = '';

    public ?int $new_product_id = null;

    public $import_file = null;

    public ?int $import_product_id = null;

    public string $import_title = '';

    /** @var array{sections: list<array{heading: string, body_html: string}>, tables: int, images: int, placeholders: list<string>, notes: list<string>}|null */
    public ?array $import_review = null;

    /** @var array<int, string> */
    public array $import_types = [];

    public ?int $deletingId = null;

    public function mount(): void
    {
        $this->authorize('ops.access');
    }

    #[Computed]
    public function templates(): LengthAwarePaginator
    {
        return ProposalTemplate::query()
            ->with('product')
            ->withCount('sections')
            ->latest()
            ->paginate(10);
    }

    /**
     * Products a template may propose, live from the table.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Product>
     */
    #[Computed]
    public function products(): \Illuminate\Database\Eloquent\Collection
    {
        return Product::orderBy('name')->get();
    }

    public function cancelCreate(): void
    {
        $this->reset(['new_title', 'new_product_id']);
        $this->resetValidation();
        $this->dispatch('close-template-form');
    }

    /**
     * Start a template shell, then hand over to the editor.
     */
    public function createTemplate(): void
    {
        $this->authorize('ops.access');

        $validated = $this->validate([
            'new_title' => ['required', 'string', 'max:255'],
            'new_product_id' => ['required', 'integer', 'exists:products,id'],
        ]);

        $template = ProposalTemplate::query()->create([
            'product_id' => $validated['new_product_id'],
            'title' => $validated['new_title'],
            'version' => 1,
        ]);

        activity('proposals')
            ->performedOn($template)
            ->causedBy(Auth::user())
            ->log('proposal-template.created');

        $this->redirect(route('proposal-templates.edit', $template), navigate: true);
    }

    public function cancelDelete(): void
    {
        $this->reset(['deletingId']);
        $this->resetValidation();
        $this->dispatch('close-template-delete');
    }

    /**
     * Delete an unused template. Generated instances are records and
     * block deletion — re-downloads must keep resolving.
     */
    public function delete(): void
    {
        $this->authorize('ops.access');

        $template = ProposalTemplate::findOrFail($this->deletingId);

        if ($template->proposals()->exists()) {
            $this->addError('delete', __('Templates with generated proposals cannot be deleted.'));

            return;
        }

        $template->delete();

        activity('proposals')
            ->causedBy(Auth::user())
            ->withProperties(['template_id' => $template->id, 'title' => $template->title])
            ->log('proposal-template.deleted');

        $this->reset(['deletingId']);
        $this->dispatch('close-template-delete');

        session()->flash('status', __('Template deleted.'));
    }

    public function cancelImport(): void
    {
        $this->reset(['import_file', 'import_product_id', 'import_title', 'import_review', 'import_types']);
        $this->resetValidation();
        $this->dispatch('close-template-import');
    }

    /**
     * Parse an uploaded .docx into a reviewable structure. Headings
     * become sections; tables and images queue visibly for mapping.
     */
    public function parseImport(): void
    {
        $this->authorize('ops.access');

        $validated = $this->validate([
            'import_file' => ['required', 'file', 'mimes:docx', 'max:10240'],
        ]);

        $parsed = DocxImporter::parse($validated['import_file']->getRealPath());

        if ($parsed['sections'] === []) {
            $this->addError('import_file', __('No sections could be parsed from this file.'));

            return;
        }

        $this->import_review = $parsed;
        $this->import_types = array_fill(0, count($parsed['sections']), TemplateSection::TYPE_PROSE);

        if ($this->import_title === '') {
            $this->import_title = pathinfo((string) $validated['import_file']->getClientOriginalName(), PATHINFO_FILENAME);
        }
    }

    /**
     * Commit the reviewed structure to a template. Parsed bodies are
     * sanitized like editor input; unknown placeholders stay visible in
     * the text the reviewer just approved — never silently dropped.
     */
    public function confirmImport(): void
    {
        $this->authorize('ops.access');

        abort_unless(is_array($this->import_review), 422, 'Parse a file before confirming.');

        $validated = $this->validate([
            'import_title' => ['required', 'string', 'max:255'],
            'import_product_id' => ['required', 'integer', 'exists:products,id'],
            'import_types' => ['required', 'array', 'size:'.count($this->import_review['sections'])],
            'import_types.*' => [Rule::in(TemplateSection::TYPES)],
        ]);

        $template = ProposalTemplate::query()->create([
            'product_id' => $validated['import_product_id'],
            'title' => $validated['import_title'],
            'version' => 1,
        ]);

        foreach ($this->import_review['sections'] as $index => $parsed) {
            $template->sections()->create([
                'order' => $index,
                'heading' => mb_substr($parsed['heading'], 0, 255),
                'body_html' => HtmlSanitizer::clean($parsed['body_html']),
                'type' => $validated['import_types'][$index],
                'config' => null,
            ]);
        }

        activity('proposals')
            ->performedOn($template)
            ->causedBy(Auth::user())
            ->withProperties(['sections' => count($this->import_review['sections'])])
            ->log('proposal-template.imported');

        $this->reset(['import_file', 'import_product_id', 'import_title', 'import_review', 'import_types']);
        $this->dispatch('close-template-import');

        $this->redirect(route('proposal-templates.edit', $template), navigate: true);
    }

    /**
     * Placeholder keys found in the parsed draft, for the review screen.
     *
     * @return list<string>
     */
    public function importPlaceholders(): array
    {
        return is_array($this->import_review) ? $this->import_review['placeholders'] : [];
    }

    /**
     * Unknown placeholder keys in the parsed draft — flagged, never kept.
     *
     * @return list<string>
     */
    public function importUnknown(): array
    {
        return array_values(array_diff($this->importPlaceholders(), ProposalPlaceholders::ALLOWED));
    }
}; ?>

<div class="py-12" x-data="{}" x-on:open-template-form.window="$dispatch('open-modal', 'template-form')" x-on:close-template-form.window="$dispatch('close-modal', 'template-form')" x-on:open-template-delete.window="$dispatch('open-modal', 'template-delete')" x-on:close-template-delete.window="$dispatch('close-modal', 'template-delete')" x-on:open-template-import.window="$dispatch('open-modal', 'template-import')" x-on:close-template-import.window="$dispatch('close-modal', 'template-import')">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="mb-6 flex flex-col gap-6">
            <x-section-title title="Proposal templates" subtitle="One versioned template per product. Prose is authored; figures always render live from the catalogue.">
                <x-secondary-button type="button" x-data="" x-on:click.prevent="$dispatch('open-modal', 'template-import')">
                    {{ __('Import .docx') }}
                </x-secondary-button>
                <x-primary-button type="button" x-data="" x-on:click.prevent="$dispatch('open-modal', 'template-form')">
                    {{ __('New template') }}
                </x-primary-button>
            </x-section-title>

            <x-alert flash="status" tone="success" />
            <x-input-error :messages="$errors->get('delete')" class="mt-1" />

            <x-card>
                <x-table loading-except="new_title, new_product_id, cancelCreate, import_file, import_product_id, import_title, import_types, cancelImport">
                    <x-table.head>
                        <x-table.row :hover="false">
                            <x-table.heading>Template</x-table.heading>
                            <x-table.heading>Product</x-table.heading>
                            <x-table.heading>Version</x-table.heading>
                            <x-table.heading>Sections</x-table.heading>
                            <x-table.heading><span class="sr-only">Actions</span></x-table.heading>
                        </x-table.row>
                    </x-table.head>
                    @if ($this->templates->isNotEmpty())
                        <x-table.body>
                            @foreach ($this->templates as $template)
                                <x-table.row wire:key="template-{{ $template->id }}">
                                    <x-table.cell>
                                        <div class="font-medium text-slate-900 dark:text-white">{{ $template->title }}</div>
                                        <div class="text-xs text-slate-400 dark:text-slate-500">v{{ $template->version }}</div>
                                    </x-table.cell>
                                    <x-table.cell>{{ $template->product?->name ?? '—' }}</x-table.cell>
                                    <x-table.cell>{{ $template->version }}</x-table.cell>
                                    <x-table.cell>{{ $template->sections_count }}</x-table.cell>
                                    <x-table.cell>
                                        <span class="flex items-center gap-1">
                                            <x-icon-button tone="brand" :href="route('proposal-templates.edit', $template)" wire:navigate label="Edit template">
                                                <x-lucide-pencil class="w-4 h-4" aria-hidden="true" />
                                            </x-icon-button>
                                            <x-icon-button tone="danger" x-data="" x-on:click.prevent="$dispatch('open-modal', 'template-delete'); $wire.set('deletingId', {{ $template->id }})" label="Delete template">
                                                <x-lucide-trash-2 class="w-4 h-4" aria-hidden="true" />
                                            </x-icon-button>
                                        </span>
                                    </x-table.cell>
                                </x-table.row>
                            @endforeach
                        </x-table.body>
                    @else
                        <x-table.empty>
                            <x-empty-state title="No templates yet" message="Start one from scratch or import an existing .docx proposal." />
                        </x-table.empty>
                    @endif
                </x-table>

                <div class="mt-4">
                    {{ $this->templates->links() }}
                </div>
            </x-card>

            <x-modal name="template-form" focusable>
                <form wire:submit="createTemplate" class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        {{ __('New template') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('Sections are authored in the editor on the next screen.') }}
                    </p>
                    <div class="mt-6 flex flex-col gap-4">
                        <div>
                            <x-input-label for="new_title" :value="__('Title')" />
                            <x-text-input wire:model="new_title" id="new_title" class="mt-1 block w-full" type="text" name="new_title" required maxlength="255" />
                            <x-input-error :messages="$errors->get('new_title')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="new_product_id" :value="__('Product')" />
                            <x-select wire:model="new_product_id" id="new_product_id" name="new_product_id" required class="mt-1 block w-full">
                                <option value="">{{ __('Pick a product') }}</option>
                                @foreach ($this->products as $product)
                                    <option value="{{ $product->id }}">{{ $product->name }}</option>
                                @endforeach
                            </x-select>
                            <x-input-error :messages="$errors->get('new_product_id')" class="mt-2" />
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <x-tertiary-button type="button" wire:click="cancelCreate" x-on:click="$dispatch('close')">
                            {{ __('Cancel') }}
                        </x-tertiary-button>
                        <x-primary-button wire:loading.attr="disabled" wire:target="createTemplate">
                            <span wire:loading.remove wire:target="createTemplate">{{ __('Create & edit') }}</span>
                            <span wire:loading wire:target="createTemplate">{{ __('Creating…') }}</span>
                        </x-primary-button>
                    </div>
                </form>
            </x-modal>

            <x-modal name="template-delete" focusable>
                <form wire:submit="delete" class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        {{ __('Delete this template?') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('Templates with generated proposals cannot be deleted. This cannot be undone.') }}
                    </p>
                    <div class="mt-6 flex justify-end">
                        <x-secondary-button type="button" wire:click="cancelDelete">
                            {{ __('Cancel') }}
                        </x-secondary-button>
                        <x-danger-button class="ms-3" wire:loading.attr="disabled" wire:target="delete">
                            <span wire:loading.remove wire:target="delete">{{ __('Delete') }}</span>
                            <span wire:loading wire:target="delete">{{ __('Deleting…') }}</span>
                        </x-danger-button>
                    </div>
                </form>
            </x-modal>

            <x-modal name="template-import" focusable maxWidth="xl">
                <div class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        {{ __('Import .docx') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('Word files only — PDFs never parse. Headings become sections; tables and images queue below for human mapping.') }}
                    </p>

                    @if ($import_review === null)
                        <form wire:submit="parseImport" class="mt-6 flex flex-col gap-4">
                            <div>
                                <x-input-label for="import_file" :value="__('Word file')" />
                                <input wire:model="import_file" id="import_file" type="file" accept=".docx" class="mt-1 block w-full text-sm text-slate-600 dark:text-slate-300 file:me-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200 dark:file:bg-white/10 dark:file:text-slate-200" />
                                <x-input-error :messages="$errors->get('import_file')" class="mt-2" />
                            </div>
                            <div class="flex justify-end gap-2">
                                <x-tertiary-button type="button" wire:click="cancelImport" x-on:click="$dispatch('close')">
                                    {{ __('Cancel') }}
                                </x-tertiary-button>
                                <x-primary-button wire:loading.attr="disabled" wire:target="parseImport">
                                    <span wire:loading.remove wire:target="parseImport">{{ __('Parse file') }}</span>
                                    <span wire:loading wire:target="parseImport">{{ __('Parsing…') }}</span>
                                </x-primary-button>
                            </div>
                        </form>
                    @else
                        <form wire:submit="confirmImport" class="mt-6 flex flex-col gap-4">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <x-input-label for="import_title" :value="__('Title')" />
                                    <x-text-input wire:model="import_title" id="import_title" class="mt-1 block w-full" type="text" required maxlength="255" />
                                    <x-input-error :messages="$errors->get('import_title')" class="mt-2" />
                                </div>
                                <div>
                                    <x-input-label for="import_product_id" :value="__('Product')" />
                                    <x-select wire:model="import_product_id" id="import_product_id" required class="mt-1 block w-full">
                                        <option value="">{{ __('Pick a product') }}</option>
                                        @foreach ($this->products as $product)
                                            <option value="{{ $product->id }}">{{ $product->name }}</option>
                                        @endforeach
                                    </x-select>
                                    <x-input-error :messages="$errors->get('import_product_id')" class="mt-2" />
                                </div>
                            </div>

                            <div class="rounded-lg border border-slate-200 dark:border-white/10">
                                <p class="border-b border-slate-200 px-4 py-2 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:border-white/10 dark:text-slate-400">
                                    {{ __('Parsed structure (:count sections)', ['count' => count($import_review['sections'])]) }}
                                </p>
                                <ul class="flex max-h-64 flex-col gap-3 overflow-y-auto p-4">
                                    @foreach ($import_review['sections'] as $index => $parsed)
                                        <li class="flex flex-col gap-1">
                                            <div class="flex items-center justify-between gap-2">
                                                <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $index + 1 }}. {{ $parsed['heading'] !== '' ? $parsed['heading'] : '(no heading)' }}</p>
                                                <x-select wire:model="import_types.{{ $index }}" aria-label="Section type" class="w-36">
                                                    <option value="prose">{{ __('Prose') }}</option>
                                                    <option value="pricing_table">{{ __('Pricing') }}</option>
                                                    <option value="signature">{{ __('Sign-off') }}</option>
                                                </x-select>
                                            </div>
                                            <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ trim(strip_tags($parsed['body_html'])) !== '' ? mb_substr(trim(strip_tags($parsed['body_html'])), 0, 120) : '(empty body)' }}</p>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>

                            @if (count($this->importPlaceholders()) > 0)
                                <div class="rounded-lg border border-slate-200 p-4 dark:border-white/10">
                                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ __('Detected placeholders') }}</p>
                                    <p class="mt-1 font-mono text-xs text-slate-600 dark:text-slate-300">{{ implode(', ', array_map(fn ($key) => '{' . '{' . $key . '}' . '}', $this->importPlaceholders())) }}</p>
                                    @if (count($this->importUnknown()) > 0)
                                        <p class="mt-1 text-xs font-medium text-red-600 dark:text-red-400">{{ __('Unknown keys stay visible in the text: :keys', ['keys' => implode(', ', $this->importUnknown())]) }}</p>
                                    @endif
                                </div>
                            @endif

                            @if (count($import_review['notes']) > 0)
                                <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-400/20 dark:bg-amber-500/10">
                                    <p class="text-xs font-semibold uppercase tracking-wider text-amber-800 dark:text-amber-200">{{ __('Needs human mapping (:count)', ['count' => count($import_review['notes'])]) }}</p>
                                    <ul class="mt-1 flex list-disc flex-col gap-1 ps-5 text-xs text-amber-800 dark:text-amber-200/90">
                                        @foreach ($import_review['notes'] as $note)
                                            <li>{{ $note }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            <x-input-error :messages="$errors->get('import_types')" class="mt-1" />
                            <div class="flex justify-end gap-2">
                                <x-tertiary-button type="button" wire:click="cancelImport" x-on:click="$dispatch('close')">
                                    {{ __('Cancel') }}
                                </x-tertiary-button>
                                <x-primary-button wire:loading.attr="disabled" wire:target="confirmImport">
                                    <span wire:loading.remove wire:target="confirmImport">{{ __('Confirm import') }}</span>
                                    <span wire:loading wire:target="confirmImport">{{ __('Importing…') }}</span>
                                </x-primary-button>
                            </div>
                        </form>
                    @endif
                </div>
            </x-modal>
        </div>
    </div>
</div>
