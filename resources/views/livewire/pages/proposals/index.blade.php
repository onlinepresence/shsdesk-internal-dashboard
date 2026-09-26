<?php

use App\Models\Proposal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public ?int $deletingId = null;

    public function mount(): void
    {
        $this->authorize('ops.access');
    }

    #[Computed]
    public function proposals(): LengthAwarePaginator
    {
        return Proposal::query()
            ->with('template')
            ->latest()
            ->paginate(10);
    }

    public function statusTone(string $status): string
    {
        return match ($status) {
            Proposal::STATUS_ACCEPTED => 'success',
            Proposal::STATUS_SENT => 'active',
            default => 'muted',
        };
    }

    /**
     * Advance draft → sent → accepted. Instances only move forward;
     * history is the audit log.
     */
    public function advance(int $id): void
    {
        $this->authorize('ops.access');

        $proposal = Proposal::findOrFail($id);
        $next = $proposal->nextStatus();

        abort_unless($next !== null, 422, 'Accepted proposals are final.');

        $proposal->update(['status' => $next]);

        activity('proposals')
            ->performedOn($proposal)
            ->causedBy(Auth::user())
            ->withProperties(['status' => $next])
            ->log('proposal.status_changed');

        session()->flash('status', __('Proposal marked :status.', ['status' => $next]));
    }

    public function cancelDelete(): void
    {
        $this->reset(['deletingId']);
        $this->resetValidation();
        $this->dispatch('close-proposal-delete');
    }

    /**
     * Delete a draft. Sent and accepted instances are records and stay.
     */
    public function delete(): void
    {
        $this->authorize('ops.access');

        $proposal = Proposal::findOrFail($this->deletingId);

        abort_unless($proposal->status === Proposal::STATUS_DRAFT, 422, 'Only drafts can be deleted.');

        $proposal->delete();

        activity('proposals')
            ->causedBy(Auth::user())
            ->withProperties(['proposal_no' => $proposal->proposal_no])
            ->log('proposal.deleted');

        $this->reset(['deletingId']);
        $this->dispatch('close-proposal-delete');

        session()->flash('status', __('Draft deleted.'));
    }
}; ?>

<div class="py-12" x-data="{}" x-on:open-proposal-delete.window="$dispatch('open-modal', 'proposal-delete')" x-on:close-proposal-delete.window="$dispatch('close-modal', 'proposal-delete')">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="mb-6 flex flex-col gap-6">
            <x-section-title title="Proposals" subtitle="Generated instances. Each re-download renders its frozen snapshot — later edits never move it.">
                <x-button-link :href="route('proposals.create')" wire:navigate>
                    {{ __('New proposal') }}
                </x-button-link>
            </x-section-title>

            <x-alert flash="status" tone="success" />

            <x-card>
                <x-table>
                    <x-table.head>
                        <x-table.row :hover="false">
                            <x-table.heading>Proposal</x-table.heading>
                            <x-table.heading>School</x-table.heading>
                            <x-table.heading>Template</x-table.heading>
                            <x-table.heading>Status</x-table.heading>
                            <x-table.heading><span class="sr-only">Actions</span></x-table.heading>
                        </x-table.row>
                    </x-table.head>
                    @if ($this->proposals->isNotEmpty())
                        <x-table.body>
                            @foreach ($this->proposals as $proposal)
                                <x-table.row wire:key="proposal-{{ $proposal->id }}">
                                    <x-table.cell>
                                        <div class="font-medium text-slate-900 dark:text-white">{{ $proposal->proposal_no }}</div>
                                        <div class="text-xs text-slate-400 dark:text-slate-500">v{{ $proposal->template_version }} · {{ $proposal->created_at?->format('M d, Y') }}</div>
                                    </x-table.cell>
                                    <x-table.cell>{{ $proposal->values['school'] ?? '—' }}</x-table.cell>
                                    <x-table.cell>{{ $proposal->template?->title ?? '—' }}</x-table.cell>
                                    <x-table.cell>
                                        <x-badge :tone="$this->statusTone($proposal->status)">{{ ucfirst($proposal->status) }}</x-badge>
                                    </x-table.cell>
                                    <x-table.cell>
                                        <span class="flex items-center gap-1">
                                            <x-icon-button :href="route('proposals.pdf', $proposal)" label="Download PDF">
                                                <x-lucide-file-text class="w-4 h-4" aria-hidden="true" />
                                            </x-icon-button>
                                            <x-icon-button :href="route('proposals.docx', $proposal)" label="Download Word">
                                                <x-lucide-download class="w-4 h-4" aria-hidden="true" />
                                            </x-icon-button>
                                            @if ($proposal->nextStatus() !== null)
                                                <x-icon-button tone="success" wire:click="advance({{ $proposal->id }})" label="Mark {{ $proposal->nextStatus() }}">
                                                    <x-lucide-check class="w-4 h-4" aria-hidden="true" />
                                                </x-icon-button>
                                            @endif
                                            @if ($proposal->status === \App\Models\Proposal::STATUS_DRAFT)
                                                <x-icon-button tone="danger" x-data="" x-on:click.prevent="$dispatch('open-modal', 'proposal-delete'); $wire.set('deletingId', {{ $proposal->id }})" label="Delete draft">
                                                    <x-lucide-trash-2 class="w-4 h-4" aria-hidden="true" />
                                                </x-icon-button>
                                            @endif
                                        </span>
                                    </x-table.cell>
                                </x-table.row>
                            @endforeach
                        </x-table.body>
                    @else
                        <x-table.empty>
                            <x-empty-state title="No proposals yet" message="Generate the first one from a template for a school." />
                        </x-table.empty>
                    @endif
                </x-table>

                <div class="mt-4">
                    {{ $this->proposals->links() }}
                </div>
            </x-card>

            <x-modal name="proposal-delete" focusable>
                <form wire:submit="delete" class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        {{ __('Delete this draft?') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('Sent and accepted proposals are records and cannot be deleted. This cannot be undone.') }}
                    </p>
                    <div class="mt-6 flex justify-end">
                        <x-secondary-button type="button" wire:click="cancelDelete">
                            {{ __('Cancel') }}
                        </x-secondary-button>
                        <x-danger-button class="ms-3" wire:loading.attr="disabled" wire:target="delete">
                            <span wire:loading.remove wire:target="delete">{{ __('Delete draft') }}</span>
                            <span wire:loading wire:target="delete">{{ __('Deleting…') }}</span>
                        </x-danger-button>
                    </div>
                </form>
            </x-modal>
        </div>
    </div>
</div>
