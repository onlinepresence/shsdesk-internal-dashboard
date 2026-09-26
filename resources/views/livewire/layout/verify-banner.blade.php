<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public bool $sent = false;

    /**
     * Re-send the confirmation email. The verification notice page owns
     * its own resend form, so this banner never renders there — it covers
     * every other app page instead.
     */
    public function sendVerification(): void
    {
        $user = Auth::user();

        abort_unless($user !== null, 403);

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);

            return;
        }

        $user->sendEmailVerificationNotification();

        $this->sent = true;
    }
}; ?>

<div>
    @if (auth()->check() && ! auth()->user()->hasVerifiedEmail() && ! request()->routeIs('verification.notice'))
        <div class="border-b border-amber-200 bg-amber-50 dark:border-amber-400/20 dark:bg-amber-500/10">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6 lg:px-8">
                <p class="text-sm text-amber-800 dark:text-amber-200">
                    {{ __('Your email address is unverified — confirm it to unlock the full desk.') }}
                    @if ($sent)
                        <span class="font-medium">{{ __('Confirmation email re-sent, check your inbox.') }}</span>
                    @endif
                </p>
                @unless ($sent)
                    <x-secondary-button type="button" wire:click="sendVerification" wire:loading.attr="disabled" wire:target="sendVerification">
                        <span wire:loading.remove wire:target="sendVerification">{{ __('Resend confirmation email') }}</span>
                        <span wire:loading wire:target="sendVerification">{{ __('Sending…') }}</span>
                    </x-secondary-button>
                @endunless
            </div>
        </div>
    @endif
</div>
