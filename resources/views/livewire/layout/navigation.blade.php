<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<div x-data="{ sidebarOpen: false }">
    <!-- Desktop sidebar -->
    <aside class="hidden lg:fixed lg:inset-y-0 lg:left-0 lg:z-30 lg:flex lg:w-64 lg:flex-col">
        <x-sidebar-panel class="flex-1" />
    </aside>

    <!-- Mobile topbar -->
    <div class="sticky top-0 z-20 flex h-16 items-center gap-2 border-b border-gray-200 bg-white px-4 dark:border-white/10 dark:bg-gray-800 lg:hidden">
        <button type="button" @click="sidebarOpen = true" aria-label="Open navigation" class="inline-flex items-center justify-center rounded-md p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-brand dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white dark:focus:ring-accent transition duration-150 ease-in-out">
            <x-lucide-menu class="h-6 w-6" aria-hidden="true" />
        </button>
        <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2">
            <x-application-logo class="h-8 w-8" />
            <span class="text-base font-semibold tracking-tight text-brand dark:text-white">ControlDesk</span>
        </a>
    </div>

    <!-- Mobile drawer -->
    <div x-show="sidebarOpen" class="relative z-40 lg:hidden" role="dialog" aria-modal="true" style="display: none;">
        <div
            x-show="sidebarOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            @click="sidebarOpen = false"
            class="fixed inset-0 bg-gray-900/60"
            aria-hidden="true"
        ></div>
        <div class="fixed inset-y-0 left-0 flex w-72 max-w-[85vw]">
            <div
                x-show="sidebarOpen"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="-translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="-translate-x-full"
                class="relative flex w-full flex-1"
            >
                <button type="button" @click="sidebarOpen = false" aria-label="Close navigation" class="absolute right-3 top-3 z-10 inline-flex items-center justify-center rounded-md p-2 text-slate-300/80 hover:bg-white/10 hover:text-white focus:outline-none focus:ring-2 focus:ring-accent transition duration-150 ease-in-out">
                    <x-lucide-x class="h-5 w-5" aria-hidden="true" />
                </button>
                <x-sidebar-panel class="flex-1" />
            </div>
        </div>
    </div>
</div>
