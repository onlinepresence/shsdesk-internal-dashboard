<?php

use App\Mail\UserInviteMail;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    /** @var list<string> */
    public array $roles = [];

    public ?int $editingId = null;

    /** @var list<string> */
    public array $edit_roles = [];

    public function mount(): void
    {
        $this->authorize('manage-users');
    }

    #[Computed]
    public function users(): LengthAwarePaginator
    {
        return User::query()->with('roles')->orderBy('name')->paginate(10);
    }

    /**
     * Assignable roles, live from the table.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Role>
     */
    #[Computed]
    public function assignableRoles(): \Illuminate\Database\Eloquent\Collection
    {
        return Role::orderBy('name')->get();
    }

    public function openCreateModal(): void
    {
        $this->authorize('manage-users');

        $this->reset(['name', 'email', 'password', 'roles']);
        $this->resetValidation();
        $this->dispatch('open-user-form');
    }

    public function cancelCreate(): void
    {
        $this->reset(['name', 'email', 'password', 'roles']);
        $this->resetValidation();
        $this->dispatch('close-user-form');
    }

    /**
     * Invite a staff member. A blank password becomes a system-generated
     * 14-char secret; either way the plaintext leaves only in the invite
     * email. Receipt proves email ownership, so invites start verified.
     * Invited users must rotate the credential on first login.
     */
    public function create(): void
    {
        $this->authorize('manage-users');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['nullable', 'string', 'min:8'],
            'roles' => ['array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ]);

        $plainPassword = $validated['password'] ?? null;

        if ($plainPassword === null || $plainPassword === '') {
            $plainPassword = Str::random(14);
        }

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'email_verified_at' => now(),
            'password' => Hash::make($plainPassword),
            'must_change_password' => true,
        ]);

        $user->syncRoles($validated['roles'] ?? []);

        Mail::to($user->email)->send(new UserInviteMail($user, $plainPassword, route('login')));

        activity('users')
            ->performedOn($user)
            ->causedBy(Auth::user())
            ->log('user.invited');

        $this->reset(['name', 'email', 'password', 'roles']);
        $this->dispatch('close-user-form');

        session()->flash('status', __('Invite sent.'));
    }

    public function openRolesModal(int $id): void
    {
        $this->authorize('manage-users');

        $user = User::findOrFail($id);

        $this->editingId = $user->id;
        $this->edit_roles = $user->roles->pluck('name')->all();
        $this->resetValidation();
        $this->dispatch('open-user-roles');
    }

    public function cancelRoles(): void
    {
        $this->reset(['editingId', 'edit_roles']);
        $this->resetValidation();
        $this->dispatch('close-user-roles');
    }

    /**
     * Replace a staffer's roles. Your own super-admin role is
     * non-removable — the last owner cannot lock everyone out.
     */
    public function saveRoles(): void
    {
        $this->authorize('manage-users');

        $user = User::findOrFail($this->editingId);

        $validated = $this->validate([
            'edit_roles' => ['array'],
            'edit_roles.*' => ['string', Rule::exists('roles', 'name')],
        ]);

        if ($user->is(Auth::user())
            && Auth::user()->hasRole('super-admin')
            && ! in_array('super-admin', $validated['edit_roles'] ?? [], true)
        ) {
            $this->addError('edit_roles', __('You cannot remove your own super-admin role.'));

            return;
        }

        $before = $user->roles->pluck('name')->all();

        $user->syncRoles($validated['edit_roles'] ?? []);

        activity('users')
            ->performedOn($user)
            ->causedBy(Auth::user())
            ->withProperties(['before' => $before, 'after' => $user->fresh()->roles->pluck('name')->all()])
            ->log('user.roles_updated');

        $this->reset(['editingId', 'edit_roles']);
        $this->dispatch('close-user-roles');

        session()->flash('status', __('Roles updated.'));
    }

    /**
     * Flip a staffer between active and inactive. Rows are never
     * hard-deleted so audit trails keep their actor; deactivated
     * accounts fail closed at login. You cannot deactivate yourself.
     */
    public function toggleActive(int $id): void
    {
        $this->authorize('manage-users');

        $user = User::findOrFail($id);

        if ($user->is(Auth::user())) {
            $this->addError('toggle', __('You cannot deactivate your own account.'));

            return;
        }

        $user->update(['is_active' => ! $user->is_active]);

        activity('users')
            ->performedOn($user)
            ->causedBy(Auth::user())
            ->withProperties(['is_active' => $user->is_active])
            ->log($user->is_active ? 'user.reactivated' : 'user.deactivated');

        session()->flash('status', $user->is_active ? __('Account reactivated.') : __('Account deactivated.'));
    }

    /**
     * Rotate to a fresh system-generated secret and re-send the invite.
     * Sets the rotation flag; only the staffer's own change clears it.
     */
    public function resendInvite(int $id): void
    {
        $this->authorize('manage-users');

        $user = User::findOrFail($id);

        $plainPassword = Str::random(14);

        $user->update([
            'password' => Hash::make($plainPassword),
            'must_change_password' => true,
        ]);

        Mail::to($user->email)->send(new UserInviteMail($user, $plainPassword, route('login')));

        activity('users')
            ->performedOn($user)
            ->causedBy(Auth::user())
            ->log('user.invite_resent');

        session()->flash('status', __('Invite re-sent.'));
    }
}; ?>

<div class="py-12" x-data="{}" x-on:open-user-form.window="$dispatch('open-modal', 'user-form')" x-on:close-user-form.window="$dispatch('close-modal', 'user-form')" x-on:open-user-roles.window="$dispatch('open-modal', 'user-roles')" x-on:close-user-roles.window="$dispatch('close-modal', 'user-roles')">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="mb-6 flex flex-col gap-6">
            <x-section-title title="Users" subtitle="Staff accounts. Invites carry a one-time credential the staffer rotates on first login.">
                <x-primary-button type="button" wire:click="openCreateModal">
                    {{ __('Invite user') }}
                </x-primary-button>
            </x-section-title>

            <x-alert flash="status" tone="success" />
            <x-input-error :messages="$errors->get('toggle')" class="mt-1" />

            <x-card>
                <x-table>
                    <x-table.head>
                        <x-table.row :hover="false">
                            <x-table.heading>Staff</x-table.heading>
                            <x-table.heading>Roles</x-table.heading>
                            <x-table.heading>Status</x-table.heading>
                            <x-table.heading><span class="sr-only">Actions</span></x-table.heading>
                        </x-table.row>
                    </x-table.head>
                    @if ($this->users->isNotEmpty())
                        <x-table.body>
                            @foreach ($this->users as $staff)
                                <x-table.row wire:key="user-{{ $staff->id }}">
                                    <x-table.cell>
                                        <div class="font-medium text-slate-900 dark:text-white">{{ $staff->name }}</div>
                                        <div class="text-xs text-slate-400 dark:text-slate-500">{{ $staff->email }}</div>
                                    </x-table.cell>
                                    <x-table.cell>
                                        <span class="flex flex-wrap items-center gap-1">
                                            @forelse ($staff->roles as $role)
                                                <x-badge tone="muted">{{ $role->name }}</x-badge>
                                            @empty
                                                <span class="text-sm text-slate-400 dark:text-slate-500">—</span>
                                            @endforelse
                                        </span>
                                    </x-table.cell>
                                    <x-table.cell>
                                        <span class="flex flex-wrap items-center gap-1">
                                            <x-badge :tone="$staff->is_active ? 'success' : 'danger'">{{ $staff->is_active ? 'Active' : 'Inactive' }}</x-badge>
                                            @if ($staff->must_change_password)
                                                <x-badge tone="warn">Must change password</x-badge>
                                            @endif
                                        </span>
                                    </x-table.cell>
                                    <x-table.cell>
                                        <span class="flex items-center gap-2">
                                            <x-tertiary-button type="button" wire:click="openRolesModal({{ $staff->id }})">
                                                {{ __('Roles') }}
                                            </x-tertiary-button>
                                            <x-tertiary-button type="button" wire:click="toggleActive({{ $staff->id }})">
                                                {{ $staff->is_active ? __('Deactivate') : __('Reactivate') }}
                                            </x-tertiary-button>
                                            <x-tertiary-button type="button" wire:click="resendInvite({{ $staff->id }})">
                                                {{ __('Resend invite') }}
                                            </x-tertiary-button>
                                        </span>
                                    </x-table.cell>
                                </x-table.row>
                            @endforeach
                        </x-table.body>
                    @else
                        <x-table.empty>
                            <x-empty-state title="No staff yet" message="Invite the first staffer to share the ops load." />
                        </x-table.empty>
                    @endif
                </x-table>

                <div class="mt-4">
                    {{ $this->users->links() }}
                </div>
            </x-card>

            <x-modal name="user-form" focusable>
                <form wire:submit="create" class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        {{ __('Invite user') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('Blank password generates a 14-character secret. The invite email carries the credential once.') }}
                    </p>
                    <div class="mt-6 flex flex-col gap-4">
                        <div>
                            <x-input-label for="name" :value="__('Name')" />
                            <x-text-input wire:model="name" id="name" class="mt-1 block w-full" type="text" name="name" required maxlength="255" />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="email" :value="__('Email')" />
                            <x-text-input wire:model="email" id="email" class="mt-1 block w-full" type="email" name="email" required maxlength="255" />
                            <x-input-error :messages="$errors->get('email')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="password" :value="__('Password (optional)')" />
                            <x-text-input wire:model="password" id="password" class="mt-1 block w-full font-mono" type="text" name="password" autocomplete="new-password" placeholder="Blank = generate" />
                            <x-input-error :messages="$errors->get('password')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label :value="__('Roles')" />
                            <div class="mt-2 flex flex-col gap-2">
                                @foreach ($this->assignableRoles as $role)
                                    <label class="flex cursor-pointer items-center gap-3" wire:key="role-{{ $role->id }}">
                                        <input wire:model="roles" type="checkbox" value="{{ $role->name }}" class="rounded border-slate-300 dark:border-white/20 dark:bg-ink text-brand shadow-sm focus:ring-brand dark:focus:ring-accent dark:focus:ring-offset-deep" />
                                        <span class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $role->name }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <x-input-error :messages="$errors->get('roles')" class="mt-2" />
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <x-tertiary-button type="button" wire:click="cancelCreate" x-on:click="$dispatch('close')">
                            {{ __('Cancel') }}
                        </x-tertiary-button>
                        <x-primary-button wire:loading.attr="disabled" wire:target="create">
                            <span wire:loading.remove wire:target="create">{{ __('Send invite') }}</span>
                            <span wire:loading wire:target="create">{{ __('Sending…') }}</span>
                        </x-primary-button>
                    </div>
                </form>
            </x-modal>

            <x-modal name="user-roles" focusable>
                <form wire:submit="saveRoles" class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        {{ __('Edit roles') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('Role membership grants every ability the role holds.') }}
                    </p>
                    <div class="mt-6 flex flex-col gap-2">
                        @foreach ($this->assignableRoles as $role)
                            <label class="flex cursor-pointer items-center gap-3" wire:key="edit-role-{{ $role->id }}">
                                <input wire:model="edit_roles" type="checkbox" value="{{ $role->name }}" class="rounded border-slate-300 dark:border-white/20 dark:bg-ink text-brand shadow-sm focus:ring-brand dark:focus:ring-accent dark:focus:ring-offset-deep" />
                                <span class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $role->name }}</span>
                            </label>
                        @endforeach
                    </div>
                    <x-input-error :messages="$errors->get('edit_roles')" class="mt-2" />
                    <div class="mt-6 flex justify-end gap-2">
                        <x-tertiary-button type="button" wire:click="cancelRoles" x-on:click="$dispatch('close')">
                            {{ __('Cancel') }}
                        </x-tertiary-button>
                        <x-primary-button wire:loading.attr="disabled" wire:target="saveRoles">
                            <span wire:loading.remove wire:target="saveRoles">{{ __('Save roles') }}</span>
                            <span wire:loading wire:target="saveRoles">{{ __('Saving…') }}</span>
                        </x-primary-button>
                    </div>
                </form>
            </x-modal>
        </div>
    </div>
</div>
