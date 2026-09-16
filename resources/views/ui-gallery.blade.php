{{-- Local-only component gallery. Not linked in nav; see the ui-gallery route. --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('UI Gallery') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 flex flex-col gap-8">

            <section class="flex flex-col gap-4">
                <x-section-title title="Section titles" subtitle="Title, optional subtitle, and an aside slot for actions.">
                    <x-badge tone="active">Aside slot</x-badge>
                </x-section-title>
                <x-section-title title="Bare title" />
            </section>

            <section class="flex flex-col gap-4">
                <x-section-title title="Cards" subtitle="Title and actions slots, optional footer." />
                <x-card>
                    <x-slot name="title">Card with title + actions</x-slot>
                    <x-slot name="actions"><x-badge tone="active">Active</x-badge></x-slot>
                    <x-slot name="footer">Footer slot content.</x-slot>
                    <p>Body slot content. Extra classes merge via $attributes.</p>
                </x-card>
                <x-card class="border-dashed">
                    <p>Bare card, no header or footer — just the body slot.</p>
                </x-card>
            </section>

            <section class="flex flex-col gap-4">
                <x-section-title title="Stats" />
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-stat label="Deployments" value="128" hint="12 this week" />
                    <x-stat label="Active licences" value="86%" />
                    <x-stat label="Open alerts" value="3" hint="1 critical" />
                </div>
            </section>

            <section class="flex flex-col gap-4">
                <x-section-title title="Table" subtitle="Responsive wrapper built in; empty slot renders when the body is empty." />
                <x-table>
                    <x-slot name="head">
                        <th>Name</th>
                        <th>Status</th>
                        <th class="!text-right">Seats</th>
                    </x-slot>
                    <x-slot name="body">
                        <tr>
                            <td>Acme Corp</td>
                            <td><x-badge tone="success">Active</x-badge></td>
                            <td class="text-right">42</td>
                        </tr>
                        <tr>
                            <td>Globex</td>
                            <td><x-badge tone="warn">Expiring</x-badge></td>
                            <td class="text-right">17</td>
                        </tr>
                        <tr>
                            <td>Initech</td>
                            <td><x-badge tone="danger">Suspended</x-badge></td>
                            <td class="text-right">0</td>
                        </tr>
                    </x-slot>
                </x-table>
                <x-table>
                    <x-slot name="head">
                        <th>Name</th>
                        <th>Status</th>
                    </x-slot>
                    <x-slot name="empty">
                        <x-empty-state title="No rows yet" message="The empty slot renders when the table body is empty." />
                    </x-slot>
                </x-table>
            </section>

            <section class="flex flex-col gap-4">
                <x-section-title title="Badges" subtitle="All five tones." />
                <div class="flex flex-wrap items-center gap-2">
                    <x-badge tone="active">Active</x-badge>
                    <x-badge tone="success">Success</x-badge>
                    <x-badge tone="warn">Warn</x-badge>
                    <x-badge tone="danger">Danger</x-badge>
                    <x-badge tone="muted">Muted</x-badge>
                    <x-badge>Default (muted)</x-badge>
                </div>
            </section>

            <section class="flex flex-col gap-4">
                <x-section-title title="Alerts" subtitle="All four tones, optional title, dismissible." />
                <div class="flex flex-col gap-3">
                    <x-alert tone="success" title="Deployed">Release v2.4.1 is live on all nodes.</x-alert>
                    <x-alert tone="danger" title="Sync failed">The licence server did not respond.</x-alert>
                    <x-alert tone="warn">Certificate expires in 9 days.</x-alert>
                    <x-alert tone="info">Maintenance window opens Sunday 02:00 UTC.</x-alert>
                    <x-alert tone="info" :dismissible="false">Pinned notice, no dismiss button.</x-alert>
                    <x-alert tone="danger" title="Delete environment?">
                        This will remove 3 deployments and cannot be undone.
                        <x-slot name="actions">
                            <x-danger-button type="button">Delete</x-danger-button>
                            <x-secondary-button type="button">Keep</x-secondary-button>
                        </x-slot>
                    </x-alert>
                    {{-- Flash support: renders session('status') when present, e.g. <x-alert flash="status" tone="success" /> --}}
                    <x-alert flash="status" tone="success" />
                </div>
            </section>

            <section class="flex flex-col gap-4">
                <x-section-title title="Empty state" />
                <x-card>
                    <x-empty-state title="Nothing here yet" message="Use the action slot for a call to action.">
                        <x-badge tone="active">Action slot</x-badge>
                    </x-empty-state>
                </x-card>
            </section>

            <section class="flex flex-col gap-4">
                <x-section-title title="Modals" subtitle="Stock dialog, opened and closed with Alpine events — no page logic." />
                <div class="flex flex-wrap items-center gap-2">
                    <x-secondary-button type="button" x-data="" @click="$dispatch('open-modal', 'gallery-confirm')">Open confirm dialog</x-secondary-button>
                    <x-secondary-button type="button" x-data="" @click="$dispatch('open-modal', 'gallery-notice')">Open small notice</x-secondary-button>
                </div>
            </section>

            <x-modal name="gallery-confirm" :show="false" focusable maxWidth="md">
                <div class="p-6">
                    <h2 class="text-lg font-medium text-slate-900 dark:text-white">Delete deployment?</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">This action cannot be undone.</p>
                    <div class="mt-6 flex justify-end gap-2">
                        <x-secondary-button type="button" x-data="" @click="$dispatch('close-modal', 'gallery-confirm')">Cancel</x-secondary-button>
                        <x-danger-button type="button">Delete</x-danger-button>
                    </div>
                </div>
            </x-modal>

            <x-modal name="gallery-notice" :show="false" maxWidth="sm">
                <div class="p-6">
                    <h2 class="text-lg font-medium text-slate-900 dark:text-white">Sync complete</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">All licences are up to date.</p>
                    <div class="mt-6 flex justify-end">
                        <x-secondary-button type="button" x-data="" @click="$dispatch('close-modal', 'gallery-notice')">Close</x-secondary-button>
                    </div>
                </div>
            </x-modal>

        </div>
    </div>
</x-app-layout>
