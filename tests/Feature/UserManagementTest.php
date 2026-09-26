<?php

use App\Livewire\SetupPanel;
use App\Mail\UserInviteMail;
use App\Models\Feature;
use App\Models\Setting;
use App\Models\User;
use App\Support\EnvWriter;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

test('access model grants super-admin every ability through permissions', function () {
    $role = Role::findByName('super-admin');

    expect($role->permissions->pluck('name')->all())
        ->toEqualCanonicalizing(['manage-users', 'manage-catalogue', 'ops.access']);

    $this->actingAs(owner());

    expect(Gate::allows('manage-users'))->toBeTrue()
        ->and(Gate::allows('manage-catalogue'))->toBeTrue()
        ->and(Gate::allows('ops.access'))->toBeTrue();

    $this->actingAs(User::factory()->create());

    expect(Gate::allows('manage-users'))->toBeFalse()
        ->and(Gate::allows('manage-catalogue'))->toBeFalse()
        ->and(Gate::allows('ops.access'))->toBeFalse();
});

test('super admin seeder creates the owner once and never clobbers', function () {
    $this->seed(SuperAdminSeeder::class);

    $user = User::where('email', SuperAdminSeeder::EMAIL)->firstOrFail();

    expect(Hash::check(SuperAdminSeeder::PASSWORD, $user->password))->toBeTrue()
        ->and($user->must_change_password)->toBeTrue()
        ->and($user->hasRole('super-admin'))->toBeTrue();

    $this->seed(SuperAdminSeeder::class);

    expect(User::where('email', SuperAdminSeeder::EMAIL)->count())->toBe(1);
    expect(Hash::check(SuperAdminSeeder::PASSWORD, User::where('email', SuperAdminSeeder::EMAIL)->firstOrFail()->password))->toBeTrue();
});

test('super admin seeder never touches a pre-existing row', function () {
    $existing = User::factory()->create([
        'email' => SuperAdminSeeder::EMAIL,
        'must_change_password' => false,
    ]);

    $hash = $existing->password;

    $this->seed(SuperAdminSeeder::class);

    $existing->refresh();

    expect($existing->password)->toBe($hash)
        ->and($existing->must_change_password)->toBeFalse();
});

test('flagged users are fenced to profile until they rotate', function () {
    $flagged = User::factory()->create(['must_change_password' => true]);

    $this->actingAs($flagged);

    $this->get(route('dashboard'))->assertRedirect(route('profile'));
    $this->get(route('deployments.index'))->assertRedirect(route('profile'));
    $this->get(route('users.index'))->assertRedirect(route('profile'));
    $this->get(route('profile'))->assertOk();

    // Logout stays reachable: the fence must never trap a session.
    Volt::test('layout.navigation')
        ->call('logout')
        ->assertRedirect('/');

    $this->assertGuest();
});

test('unflagged users move freely', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertOk();
    $this->get(route('profile'))->assertOk();
});

test('self-initiated change clears the flag', function () {
    $flagged = User::factory()->create(['must_change_password' => true]);

    $this->actingAs($flagged);

    Volt::test('profile.update-password-form')
        ->set('current_password', 'password')
        ->set('password', 'rotated-secret')
        ->set('password_confirmation', 'rotated-secret')
        ->call('updatePassword')
        ->assertHasNoErrors();

    expect($flagged->refresh()->must_change_password)->toBeFalse();

    $this->get(route('dashboard'))->assertOk();
});

test('users page is gated to manage-users with owner bypass', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('users.index'))->assertForbidden();
    Volt::test('pages.users.index')->assertForbidden();

    $this->actingAs(owner());

    $this->get(route('users.index'))->assertOk();
    Volt::test('pages.users.index')->assertHasNoErrors();
});

test('invite sends credentials with a generated secret by default', function () {
    Mail::fake();

    $this->actingAs(owner());

    Volt::test('pages.users.index')
        ->set('name', 'Ama Serwaa')
        ->set('email', 'ama@example.com')
        ->set('password', '')
        ->call('create')
        ->assertHasNoErrors();

    $staff = User::where('email', 'ama@example.com')->firstOrFail();

    expect($staff->must_change_password)->toBeTrue();

    Mail::assertSent(UserInviteMail::class, function (UserInviteMail $mail) use ($staff): bool {
        return $mail->staff->is($staff)
            && strlen($mail->plainPassword) === 14
            && Hash::check($mail->plainPassword, $staff->fresh()->password);
    });

    $this->assertDatabaseHas('activity_log', ['description' => 'user.invited']);
});

test('invite honors a provided password', function () {
    Mail::fake();

    $this->actingAs(owner());

    Volt::test('pages.users.index')
        ->set('name', 'Kofi Mensah')
        ->set('email', 'kofi@example.com')
        ->set('password', 'provided-secret')
        ->call('create')
        ->assertHasNoErrors();

    $staff = User::where('email', 'kofi@example.com')->firstOrFail();

    Mail::assertSent(UserInviteMail::class, function (UserInviteMail $mail) use ($staff): bool {
        return $mail->staff->is($staff)
            && $mail->plainPassword === 'provided-secret'
            && Hash::check('provided-secret', $staff->fresh()->password);
    });
});

test('roles update and self-demotion is refused', function () {
    $staff = User::factory()->create();
    $admin = owner();

    $this->actingAs($admin);

    Volt::test('pages.users.index')
        ->call('openRolesModal', $staff->id)
        ->set('edit_roles', ['super-admin'])
        ->call('saveRoles')
        ->assertHasNoErrors();

    expect($staff->refresh()->hasRole('super-admin'))->toBeTrue();

    Volt::test('pages.users.index')
        ->call('openRolesModal', $admin->id)
        ->set('edit_roles', [])
        ->call('saveRoles')
        ->assertHasErrors(['edit_roles']);

    expect($admin->refresh()->hasRole('super-admin'))->toBeTrue();
});

test('deactivation blocks login and self-deactivation is refused', function () {
    $staff = User::factory()->create();
    $admin = owner();

    $this->actingAs($admin);

    Volt::test('pages.users.index')
        ->call('toggleActive', $staff->id)
        ->assertHasNoErrors();

    expect($staff->refresh()->is_active)->toBeFalse();

    Volt::test('pages.users.index')
        ->call('toggleActive', $admin->id)
        ->assertHasErrors(['toggle']);

    expect($admin->refresh()->is_active)->toBeTrue();

    // Drop the admin session: the rejected login below must prove the
    // staff account itself cannot authenticate, not inherit one.
    auth()->logout();

    Volt::test('pages.auth.login')
        ->set('form.email', $staff->email)
        ->set('form.password', 'password')
        ->call('login')
        ->assertHasErrors();

    $this->assertGuest();
});

test('resend invite rotates credentials and keeps the flag', function () {
    Mail::fake();

    $this->actingAs(owner());

    $staff = User::factory()->create(['must_change_password' => false]);

    Volt::test('pages.users.index')
        ->call('resendInvite', $staff->id)
        ->assertHasNoErrors();

    expect($staff->refresh()->must_change_password)->toBeTrue();

    Mail::assertSent(UserInviteMail::class, function (UserInviteMail $mail) use ($staff): bool {
        return $mail->staff->is($staff)
            && strlen($mail->plainPassword) === 14
            && Hash::check($mail->plainPassword, $staff->fresh()->password);
    });

    $this->assertDatabaseHas('activity_log', ['description' => 'user.invite_resent']);
});

test('setup panel shows for super-admin while gaps exist', function () {
    config()->set('licence-export.signing_key', null);

    $this->actingAs(owner());

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('First-run setup');
});

test('setup panel hides when clean and never for other staff', function () {
    config()->set('licence-export.signing_key', str_repeat('a', 64));
    config()->set('mail.from.address', 'desk@example.com');
    $this->seed(CatalogueSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->actingAs(owner());

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('First-run setup');

    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('First-run setup');
});

test('setup panel actions seed and generate idempotently', function () {
    config()->set('licence-export.signing_key', null);

    $writerPath = tempnam(sys_get_temp_dir(), 'setupenv');
    file_put_contents($writerPath, '');
    $this->app->instance(EnvWriter::class, new EnvWriter($writerPath));

    try {
        $this->actingAs(owner());

        Livewire::test(SetupPanel::class)
            ->call('seedCatalogueAndSettings')
            ->assertHasNoErrors();

        expect(Feature::query()->exists())->toBeTrue()
            ->and(Setting::query()->exists())->toBeTrue();

        Livewire::test(SetupPanel::class)
            ->call('generateSigningKey')
            ->assertHasNoErrors();

        expect((new EnvWriter($writerPath))->readValue('LICENCE_SIGNING_KEY'))->toHaveLength(64);
    } finally {
        @unlink($writerPath);
    }

    $this->actingAs(User::factory()->create());

    Livewire::test(SetupPanel::class)->assertForbidden();
});

test('permission-gated sidebar hides users without the grant', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertDontSee('/users', false);

    $this->actingAs(owner());

    $this->get(route('dashboard'))->assertSee('/users', false);
});

test('direct permissions toggle abilities without roles', function () {
    $staff = User::factory()->create();

    $this->actingAs($staff);
    $this->get(route('users.index'))->assertForbidden();

    $staff->givePermissionTo('manage-users');

    $this->get(route('users.index'))->assertOk();

    $staff->revokePermissionTo('manage-users');

    $this->get(route('users.index'))->assertForbidden();
});

test('access form saves direct permissions end to end', function () {
    $staff = User::factory()->create();

    $this->actingAs(owner());

    Volt::test('pages.users.index')
        ->call('openRolesModal', $staff->id)
        ->set('edit_permissions', ['ops.access'])
        ->call('saveRoles')
        ->assertHasNoErrors();

    expect($staff->refresh()->hasPermissionTo('ops.access'))->toBeTrue();

    $this->actingAs($staff);
    $this->get(route('deployments.index'))->assertOk();
    $this->get(route('users.index'))->assertForbidden();
});

test('own row shows a marker instead of management buttons', function () {
    $admin = owner();
    User::factory()->create();

    $this->actingAs($admin);

    $content = $this->get(route('users.index'))->assertOk()->getContent();

    expect($content)->toContain('You');
    // The staffer row carries icon buttons (aria-label plus title each);
    // the own row carries none.
    expect(substr_count($content, 'Edit access'))->toBe(2);
    expect(substr_count($content, 'Resend invite'))->toBe(2);
});

test('unverified staff see the resend banner on app pages', function () {
    $this->actingAs(User::factory()->unverified()->create());

    $this->get(route('profile'))
        ->assertOk()
        ->assertSee('Resend confirmation email');
});

test('banner hides on the verification notice and for verified staff', function () {
    $this->actingAs(User::factory()->unverified()->create());

    $this->get(route('verification.notice'))
        ->assertOk()
        ->assertDontSee('Resend confirmation email');

    $this->actingAs(User::factory()->create());

    $this->get(route('profile'))->assertDontSee('Resend confirmation email');
});

test('banner resends the confirmation email', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $this->actingAs($user);

    Volt::test('layout.verify-banner')
        ->call('sendVerification')
        ->assertHasNoErrors();

    Notification::assertSentTo($user, VerifyEmail::class);
});
