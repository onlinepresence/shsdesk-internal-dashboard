<div>
    @if ($this->shouldShow)
    <div class="mb-6">
        <x-card>
            <x-slot name="title">First-run setup</x-slot>
            <x-slot name="actions">
                <x-badge tone="warn">Gaps remain</x-badge>
            </x-slot>

            <x-alert flash="status" tone="success" />

            <ul class="flex flex-col gap-4">
                <li class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-medium text-slate-900 dark:text-white">Application key</p>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Encrypts sessions and cookies. Set it once via <code class="font-mono">php artisan desk:setup</code>.</p>
                    </div>
                    @if (empty(config('app.key')))
                        <x-badge tone="muted">SKIP</x-badge>
                    @else
                        <x-badge tone="success">DONE</x-badge>
                    @endif
                </li>

                <li class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-medium text-slate-900 dark:text-white">Licence signing key</p>
                        <p class="text-sm text-slate-500 dark:text-slate-400">
                            Signs every licence and demo document. Manage keys on the
                            <a href="{{ route('demo-keys.index') }}" wire:navigate class="font-medium text-brand underline hover:text-deep dark:text-slate-200 dark:hover:text-white">demo keys</a>
                            page.
                        </p>
                        <x-input-error :messages="$errors->get('signing_key')" class="mt-1" />
                    </div>
                    <span class="flex items-center gap-2">
                        @if ($this->signingKeyMissing)
                            <x-badge tone="muted">SKIP</x-badge>
                            <x-secondary-button type="button" wire:click="generateSigningKey" wire:loading.attr="disabled" wire:target="generateSigningKey">
                                <span wire:loading.remove wire:target="generateSigningKey">{{ __('Generate key') }}</span>
                                <span wire:loading wire:target="generateSigningKey">{{ __('Generating…') }}</span>
                            </x-secondary-button>
                        @else
                            <x-badge tone="success">DONE</x-badge>
                        @endif
                    </span>
                </li>

                <li class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-medium text-slate-900 dark:text-white">Catalogue &amp; settings</p>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Features, pricing globals, and invoice defaults the quote maths reads.</p>
                    </div>
                    <span class="flex items-center gap-2">
                        @if ($this->catalogueReady)
                            <x-badge tone="success">DONE</x-badge>
                        @else
                            <x-badge tone="muted">SKIP</x-badge>
                            <x-secondary-button type="button" wire:click="seedCatalogueAndSettings" wire:loading.attr="disabled" wire:target="seedCatalogueAndSettings">
                                <span wire:loading.remove wire:target="seedCatalogueAndSettings">{{ __('Seed now') }}</span>
                                <span wire:loading wire:target="seedCatalogueAndSettings">{{ __('Seeding…') }}</span>
                            </x-secondary-button>
                        @endif
                    </span>
                </li>

                <li class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-medium text-slate-900 dark:text-white">Mail sender</p>
                        @if ($this->mailFrom !== null)
                            <p class="text-sm text-slate-500 dark:text-slate-400">Invites send from <code class="font-mono">{{ $this->mailFrom }}</code>.</p>
                        @else
                            <p class="text-sm text-slate-500 dark:text-slate-400">Set <code class="font-mono">MAIL_FROM_ADDRESS</code> in <code class="font-mono">.env</code> (see the Laravel mail documentation) so staff invites reach real inboxes.</p>
                        @endif
                    </div>
                    @if ($this->mailFrom !== null)
                        <x-badge tone="success">DONE</x-badge>
                    @else
                        <x-badge tone="muted">SKIP</x-badge>
                    @endif
                </li>
            </ul>

            <x-slot name="footer">Each item turns DONE as its gap closes; the panel disappears when clean.</x-slot>
        </x-card>
    </div>
    @endif
</div>
