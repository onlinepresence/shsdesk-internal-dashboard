<?php

use App\Models\Deployment;
use App\Models\Licence;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    #[Computed]
    public function deployments(): Collection
    {
        return Deployment::with('latestLicence')->latest()->get();
    }

    /**
     * @return array{label: string, tone: string}
     */
    public function licenceState(?Licence $licence): array
    {
        if ($licence === null) {
            return ['label' => 'No licence', 'tone' => 'muted'];
        }

        if ($licence->isExpired()) {
            return ['label' => 'Expired', 'tone' => 'danger'];
        }

        if ($licence->isExpiringSoon()) {
            return ['label' => 'Expiring', 'tone' => 'warn'];
        }

        return ['label' => 'Active', 'tone' => 'active'];
    }
}; ?>

<div class="py-12">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="mb-6 flex flex-col gap-6">
            <x-section-title title="Licences" subtitle="Terms in force per deployment, mirrored from the FlowEdu catalogue." />

            <x-alert flash="status" tone="success" />

            <x-card>
                <x-table>
                    <x-table.head>
                        <x-table.row :hover="false">
                            <x-table.heading>School</x-table.heading>
                            <x-table.heading>Status</x-table.heading>
                            <x-table.heading>Expires</x-table.heading>
                            <x-table.heading><span class="sr-only">Manage</span></x-table.heading>
                        </x-table.row>
                    </x-table.head>
                    @if ($this->deployments->isNotEmpty())
                        <x-table.body>
                            @foreach ($this->deployments as $deployment)
                                <x-table.row>
                                    <x-table.cell>
                                        <div class="font-medium text-slate-900 dark:text-white">{{ $deployment->school_name }}</div>
                                        <div class="font-mono text-xs text-slate-400 dark:text-slate-500">{{ $deployment->uuid }}</div>
                                    </x-table.cell>
                                    <x-table.cell>
                                        @php($state = $this->licenceState($deployment->latestLicence))
                                        <x-badge :tone="$state['tone']">{{ $state['label'] }}</x-badge>
                                    </x-table.cell>
                                    <x-table.cell>{{ $deployment->latestLicence?->expires_at?->diffForHumans() ?? '—' }}</x-table.cell>
                                    <x-table.cell>
                                        <x-button-link :href="route('licences.edit', $deployment->uuid)" variant="tertiary">
                                            {{ __('Manage') }}
                                        </x-button-link>
                                    </x-table.cell>
                                </x-table.row>
                            @endforeach
                        </x-table.body>
                    @else
                        <x-table.empty>
                            <x-empty-state title="No deployments yet" message="Register a deployment before issuing licences." />
                        </x-table.empty>
                    @endif
                </x-table>
            </x-card>
        </div>
    </div>
</div>
