{{-- App sidebar panel: brand header, config-driven nav, user footer with theme toggle. Rendered twice (desktop aside + mobile drawer) by the layout navigation component; both instances stay in sync through shared config. --}}
<div {{ $attributes->merge(['class' => 'flex h-full flex-col bg-ink']) }}>
    <!-- Brand -->
    <div class="flex h-16 shrink-0 items-center gap-2 border-b border-white/10 px-4">
        <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2">
            <x-application-logo class="h-9 w-9" />
            <span class="text-lg font-semibold tracking-tight text-white">ControlDesk</span>
        </a>
    </div>

    <!-- Nav groups from config/sidebar.php -->
    <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-4">
        @foreach (config('sidebar.groups', []) as $group)
            <div>
                <p class="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $group['section'] }}</p>
                <div class="space-y-1">
                    @foreach ($group['items'] as $item)
                        @if (! isset($item['can']) || (auth()->check() && auth()->user()->can($item['can'])))
                            <x-sidebar-link
                                :label="$item['label']"
                                :route="$item['route']"
                                :icon="$item['icon'] ?? 'fallback'"
                                :params="$item['params'] ?? []"
                                :active="request()->routeIs($item['route'])"
                                wire:navigate
                            />
                        @endif
                    @endforeach
                </div>
            </div>
        @endforeach
    </nav>

    <!-- User footer -->
    <div class="shrink-0 border-t border-white/10 p-4">
        <div class="flex items-center gap-3">
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-white" x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></p>
                <p class="truncate text-xs text-slate-400">{{ auth()->user()->email }}</p>
            </div>
            <x-theme-toggle />
        </div>
        <button wire:click="logout" type="button" class="mt-3 inline-flex items-center gap-2 text-sm text-slate-300/80 hover:text-white focus:outline-none focus:ring-2 focus:ring-accent rounded-md transition duration-150 ease-in-out">
            <x-lucide-log-out class="h-4 w-4" aria-hidden="true" />
            {{ __('Log Out') }}
        </button>
    </div>
</div>
