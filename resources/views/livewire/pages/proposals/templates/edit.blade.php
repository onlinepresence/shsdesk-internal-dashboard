<?php

use App\Models\ProposalTemplate;
use App\Models\TemplateSection;
use App\Support\HtmlSanitizer;
use App\Support\ProposalPlaceholders;
use App\Support\ProposalRenderer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ProposalTemplate $template;

    public string $title = '';

    /**
     * Draft sections. Key is stable across reorders so editors survive.
     *
     * @var list<array{key: string, id: ?int, heading: ?string, body_html: ?string, type: string, source: ?string}>
     */
    public array $sections = [];

    public bool $preview = false;

    public function mount(ProposalTemplate $template): void
    {
        $this->authorize('ops.access');

        $template->loadMissing('sections');

        $this->template = $template;
        $this->title = $template->title;
        $this->sections = $template->sections->map(fn (TemplateSection $section): array => [
            'key' => 's'.$section->id,
            'id' => $section->id,
            'heading' => $section->heading,
            'body_html' => $section->body_html,
            'type' => $section->type,
            'source' => $section->pricingSource(),
        ])->all();
    }

    /**
     * Insert-helper keys, exactly the allowed set.
     *
     * @return list<string>
     */
    #[Computed]
    public function placeholderKeys(): array
    {
        return ProposalPlaceholders::ALLOWED;
    }

    /**
     * Unsaved draft through the shared renderer with sample fills — the
     * same fragment the PDF download uses, so the two cannot diverge.
     */
    public function previewHtml(): string
    {
        return ProposalRenderer::renderHtml(ProposalRenderer::resolve(
            array_map(fn (array $section): array => [
                'heading' => $section['heading'],
                'body_html' => $section['body_html'],
                'type' => $section['type'],
                'config' => $section['source'] !== null ? ['source' => $section['source']] : null,
            ], $this->sections),
            ProposalRenderer::sampleValues(),
        ));
    }

    public function togglePreview(): void
    {
        $this->authorize('ops.access');

        $this->preview = ! $this->preview;
    }

    /**
     * Append a prose section. Type changes per card afterwards.
     */
    public function addSection(): void
    {
        $this->authorize('ops.access');

        $this->sections[] = [
            'key' => 'n'.Str::random(8),
            'id' => null,
            'heading' => '',
            'body_html' => '',
            'type' => TemplateSection::TYPE_PROSE,
            'source' => null,
        ];
    }

    public function removeSection(string $key): void
    {
        $this->authorize('ops.access');

        $this->sections = array_values(array_filter(
            $this->sections,
            fn (array $section): bool => $section['key'] !== $key
        ));
    }

    public function moveUp(string $key): void
    {
        $this->authorize('ops.access');

        $this->move($key, -1);
    }

    public function moveDown(string $key): void
    {
        $this->authorize('ops.access');

        $this->move($key, 1);
    }

    /**
     * Persist the draft. Unknown placeholders block the save — they are
     * flagged on their section, never silently kept. Bodies sanitize on
     * the way in. Saving bumps the template version.
     */
    public function save(): void
    {
        $this->authorize('ops.access');

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*.id' => ['nullable', 'integer'],
            'sections.*.heading' => ['nullable', 'string', 'max:255'],
            'sections.*.body_html' => ['nullable', 'string'],
            'sections.*.type' => ['required', 'string', Rule::in(TemplateSection::TYPES)],
            'sections.*.source' => ['nullable', 'string', Rule::in(TemplateSection::PRICING_SOURCES)],
        ]);

        $failed = false;

        foreach ($validated['sections'] as $index => $section) {
            if ($section['type'] !== TemplateSection::TYPE_SIGNATURE && trim((string) ($section['heading'] ?? '')) === '') {
                $this->addError("sections.{$index}.heading", __('A heading is required.'));

                $failed = true;
            }

            if ($section['type'] === TemplateSection::TYPE_PRICING_TABLE && $section['source'] === null) {
                $this->addError("sections.{$index}.source", __('Pick the table to bind.'));

                $failed = true;
            }

            $unknown = ProposalPlaceholders::unknownKeys([$section['heading'] ?? null, $section['body_html'] ?? null]);

            if ($unknown !== []) {
                $this->addError("sections.{$index}.body_html", __('Unknown placeholders: :keys. Allowed: :allowed.', [
                    'keys' => implode(', ', array_map(fn (string $key): string => '{{'.$key.'}}', $unknown)),
                    'allowed' => implode(', ', array_map(fn (string $key): string => '{{'.$key.'}}', ProposalPlaceholders::ALLOWED)),
                ]));

                $failed = true;
            }
        }

        if ($failed) {
            $this->preview = false;

            return;
        }

        DB::transaction(function () use ($validated): void {
            $keptIds = [];

            foreach ($validated['sections'] as $index => $section) {
                $attributes = [
                    'order' => $index,
                    'heading' => $section['heading'] !== '' ? $section['heading'] : null,
                    'body_html' => $section['type'] === TemplateSection::TYPE_PROSE || $section['type'] === TemplateSection::TYPE_SIGNATURE
                        ? HtmlSanitizer::clean($section['body_html'])
                        : $section['body_html'],
                    'type' => $section['type'],
                    'config' => $section['type'] === TemplateSection::TYPE_PRICING_TABLE ? ['source' => $section['source']] : null,
                ];

                if (($section['id'] ?? null) !== null) {
                    $this->template->sections()->whereKey($section['id'])->update($attributes);
                    $keptIds[] = $section['id'];
                } else {
                    $keptIds[] = $this->template->sections()->create($attributes)->id;
                }
            }

            $this->template->sections()->whereNotIn('id', $keptIds)->delete();

            $this->template->update([
                'title' => $validated['title'],
                'version' => $this->template->version + 1,
            ]);
        });

        activity('proposals')
            ->performedOn($this->template)
            ->causedBy(Auth::user())
            ->withProperties(['version' => $this->template->fresh()->version])
            ->log('proposal-template.updated');

        session()->flash('status', __('Template saved.'));

        $this->redirect(route('proposal-templates.index'), navigate: true);
    }

    /**
     * Swap a section with its neighbour. Out-of-range moves are no-ops.
     */
    protected function move(string $key, int $direction): void
    {
        $index = array_search($key, array_column($this->sections, 'key'), true);

        if ($index === false) {
            return;
        }

        $target = $index + $direction;

        if (! isset($this->sections[$target])) {
            return;
        }

        [$this->sections[$index], $this->sections[$target]] = [$this->sections[$target], $this->sections[$index]];
    }
}; ?>

<div class="py-12">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="mb-6 flex flex-col gap-6">
            <x-section-title :title="__('Template — ').$title" subtitle="Sections render numbered, in order. Preview shows the unsaved draft exactly as the PDF will.">
                <x-secondary-button type="button" wire:click="togglePreview">
                    {{ $preview ? __('Back to edit') : __('Preview') }}
                </x-secondary-button>
                <x-button-link :href="route('proposal-templates.index')" wire:navigate variant="tertiary">
                    {{ __('Back to templates') }}
                </x-button-link>
            </x-section-title>

            @if ($preview)
                <x-card>
                    <x-slot name="title">Preview — unsaved draft, sample fills</x-slot>
                    <div class="proposal-preview rounded-lg border border-slate-200 p-6 dark:border-white/10">
                        {!! $this->previewHtml() !!}
                    </div>
                </x-card>
            @else
                <x-card>
                    <div class="flex flex-col gap-2">
                        <x-input-label for="title" :value="__('Title')" />
                        <x-text-input wire:model="title" id="title" class="block w-full" type="text" required maxlength="255" />
                        <x-input-error :messages="$errors->get('title')" />
                    </div>
                </x-card>

                @foreach ($sections as $index => $section)
                    <x-card wire:key="sec-{{ $section['key'] }}">
                        <x-slot name="title">{{ __('Section :n', ['n' => $index + 1]) }}</x-slot>
                        <x-slot name="actions">
                            <span class="flex items-center gap-1">
                                <x-icon-button wire:click="moveUp('{{ $section['key'] }}')" label="Move section up">
                                    <x-lucide-chevron-up class="w-4 h-4" aria-hidden="true" />
                                </x-icon-button>
                                <x-icon-button wire:click="moveDown('{{ $section['key'] }}')" label="Move section down">
                                    <x-lucide-chevron-down class="w-4 h-4" aria-hidden="true" />
                                </x-icon-button>
                                <x-icon-button tone="danger" wire:click="removeSection('{{ $section['key'] }}')" label="Remove section">
                                    <x-lucide-trash-2 class="w-4 h-4" aria-hidden="true" />
                                </x-icon-button>
                            </span>
                        </x-slot>

                        <div class="flex flex-col gap-4">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <x-input-label :value="__('Heading')" />
                                    <x-text-input wire:model="sections.{{ $index }}.heading" class="mt-1 block w-full" type="text" maxlength="255" />
                                    <x-input-error :messages="$errors->get('sections.'.$index.'.heading')" class="mt-2" />
                                </div>
                                <div class="flex gap-4">
                                    <div class="flex-1">
                                        <x-input-label :value="__('Type')" />
                                        <x-select wire:model.live="sections.{{ $index }}.type" class="mt-1 block w-full">
                                            <option value="prose">{{ __('Prose') }}</option>
                                            <option value="pricing_table">{{ __('Pricing table') }}</option>
                                            <option value="signature">{{ __('Sign-off') }}</option>
                                        </x-select>
                                        <x-input-error :messages="$errors->get('sections.'.$index.'.type')" class="mt-2" />
                                    </div>
                                    @if ($section['type'] === 'pricing_table')
                                        <div class="flex-1">
                                            <x-input-label :value="__('Binds to')" />
                                            <x-select wire:model="sections.{{ $index }}.source" class="mt-1 block w-full">
                                                <option value="">{{ __('Pick a table') }}</option>
                                                <option value="modules">{{ __('Modules, live prices') }}</option>
                                                <option value="bands">{{ __('Student bands') }}</option>
                                            </x-select>
                                            <x-input-error :messages="$errors->get('sections.'.$index.'.source')" class="mt-2" />
                                        </div>
                                    @endif
                                </div>
                            </div>

                            @if ($section['type'] === 'pricing_table')
                                <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Renders the bound catalogue table with current prices at preview, or the frozen snapshot on instances. No body text is rendered.') }}</p>
                            @else
                                <div>
                                    <x-input-label :value="__('Body')" />
                                    <div class="mt-2 flex flex-wrap gap-1">
                                        @foreach ($this->placeholderKeys as $key)
                                            <button type="button" data-ph="{{ '{' . '{' . $key . '}' . '}' }}" data-ed="trix-{{ $section['key'] }}" x-data="" x-on:click="const ed = document.getElementById($el.dataset.ed); if (ed && ed.editor) { ed.editor.insertString($el.dataset.ph); }" class="rounded border border-slate-300 px-2 py-1 font-mono text-xs text-slate-600 hover:bg-slate-100 dark:border-white/20 dark:text-slate-300 dark:hover:bg-white/10">{{ '{' . '{' . $key . '}' . '}' }}</button>
                                        @endforeach
                                    </div>
                                    <div wire:ignore class="mt-2">
                                        <input id="trixval-{{ $section['key'] }}" type="hidden" value="{{ $section['body_html'] }}" />
                                        <trix-editor input="trixval-{{ $section['key'] }}" x-on:trix-change="$wire.set('sections.{{ $index }}.body_html', $event.target.value)" class="trix-content"></trix-editor>
                                    </div>
                                    <x-input-error :messages="$errors->get('sections.'.$index.'.body_html')" class="mt-2" />
                                </div>
                            @endif
                        </div>
                    </x-card>
                @endforeach

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <x-secondary-button type="button" wire:click="addSection">
                        {{ __('Add section') }}
                    </x-secondary-button>
                    <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">{{ __('Save template') }}</span>
                        <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                    </x-primary-button>
                </div>
            @endif
        </div>
    </div>
</div>
