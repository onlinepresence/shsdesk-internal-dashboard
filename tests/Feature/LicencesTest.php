<?php

use App\Models\Deployment;
use App\Models\Feature;
use App\Models\Licence;
use App\Models\User;
use Database\Seeders\CatalogueSeeder;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(CatalogueSeeder::class);
});

function actingSuperAdmin(): User
{
    $user = User::factory()->create();

    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $user->assignRole('super-admin');

    return $user;
}

test('licence catalogue mirrors every FlowEdu feature key', function () {
    $core = config('licence-catalogue.core_features');
    $modules = config('licence-catalogue.modules');

    $locked = collect($core)->filter(fn (array $feature): bool => ($feature['locked'] ?? false) === true)->keys()->all();
    $toggleable = collect($core)->filter(fn (array $feature): bool => ($feature['locked'] ?? false) === false)->map(fn (array $feature): string => $feature['db_column'])->values()->all();
    $moduleKeys = collect($modules)->map(fn (array $module): string => $module['db_column'])->values()->all();

    expect($locked)->toEqualCanonicalizing(['academic_structure', 'students', 'grading', 'teacher_portal', 'student_portal']);
    expect($toggleable)->toEqualCanonicalizing(['core_timetable', 'core_attendance', 'core_memos', 'core_impersonation']);
    expect($moduleKeys)->toEqualCanonicalizing([
        'module_finance', 'module_staff_hr', 'module_reports', 'module_evaluations',
        'module_student_welfare', 'module_progression', 'module_system_admin',
        'module_teacher_tools', 'module_messaging', 'module_practicum',
    ]);

    expect(config('licence-catalogue.pricing.currency'))->toBe('GHS');
    expect(config('licence-catalogue.student_pricing_bands'))->toHaveCount(4);
});

test('licence scopes separate active, expiring, and expired rows', function () {
    $active = Licence::factory()->create();
    $expiring = Licence::factory()->expiring()->create();
    $expired = Licence::factory()->expired()->create();
    $indefinite = Licence::factory()->indefinite()->create();

    expect(Licence::active()->pluck('id')->all())
        ->toContain($active->id, $expiring->id, $indefinite->id)
        ->not->toContain($expired->id);

    expect(Licence::expiringSoon()->pluck('id')->all())
        ->toContain($expiring->id)
        ->not->toContain($active->id, $expired->id, $indefinite->id);

    expect(Licence::expired()->pluck('id')->all())
        ->toContain($expired->id)
        ->not->toContain($active->id, $expiring->id, $indefinite->id);

    expect($active->isExpired())->toBeFalse();
    expect($expiring->isExpiringSoon())->toBeTrue();
    expect($expired->isExpired())->toBeTrue();
});

test('edit page creates the first licence and logs it', function () {
    $this->actingAs(User::factory()->create());
    $deployment = Deployment::factory()->create();

    Volt::test('pages.licences.edit', ['deployment' => $deployment])
        ->set('modules', ['module_finance'])
        ->set('max_students', 500)
        ->set('starts_at', today()->toDateString())
        ->set('expires_at', today()->addYear()->toDateString())
        ->set('notes', 'First term')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('licences.index'));

    $this->assertDatabaseHas('licences', [
        'deployment_id' => $deployment->id,
        'notes' => 'First term',
    ]);
    $this->assertDatabaseHas('activity_log', ['description' => 'licence.created']);
});

test('saving an existing licence updates in place and logs it', function () {
    $this->actingAs(User::factory()->create());
    $deployment = Deployment::factory()->create();
    $licence = Licence::factory()->for($deployment)->create();

    Volt::test('pages.licences.edit', ['deployment' => $deployment])
        ->set('max_students', 750)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('licences.index'));

    expect(Licence::where('deployment_id', $deployment->id)->count())->toBe(1);
    expect($licence->fresh()->caps)->toBe(['max_active_students' => 750]);
    $this->assertDatabaseHas('activity_log', ['description' => 'licence.updated']);
});

test('renew inserts a new row carrying terms forward one year', function () {
    $this->actingAs(User::factory()->create());
    $deployment = Deployment::factory()->create();
    $current = Licence::factory()->for($deployment)->create([
        'modules' => ['module_reports' => true],
        'expires_at' => today()->addDays(10),
    ]);

    Volt::test('pages.licences.edit', ['deployment' => $deployment])
        ->call('renew')
        ->assertHasNoErrors()
        ->assertRedirect(route('licences.index'));

    $rows = Licence::where('deployment_id', $deployment->id)->orderBy('id')->get();

    expect($rows)->toHaveCount(2);
    expect($rows[1]->expires_at->toDateString())->toBe($current->expires_at->addYear()->toDateString());
    expect($rows[1]->modules)->toBe(['module_reports' => true]);
    $this->assertDatabaseHas('activity_log', ['description' => 'licence.renewed']);
});

test('expiry before start is rejected', function () {
    $this->actingAs(User::factory()->create());
    $deployment = Deployment::factory()->create();

    Volt::test('pages.licences.edit', ['deployment' => $deployment])
        ->set('starts_at', today()->toDateString())
        ->set('expires_at', today()->subDay()->toDateString())
        ->call('save')
        ->assertHasErrors(['expires_at']);
});

test('heartbeat answers the active licence terms', function () {
    $deployment = Deployment::factory()->create();
    Licence::factory()->for($deployment)->create([
        'core' => ['core_attendance' => true],
        'modules' => ['module_finance' => true],
        'caps' => ['max_active_students' => 500],
        'expires_at' => today()->addYear(),
    ]);
    $token = $deployment->createToken('heartbeat', [Deployment::HEARTBEAT_ABILITY])->plainTextToken;

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/v1/heartbeats', heartbeatPayload($deployment));

    $response->assertOk();
    $response->assertJsonPath('licence.tier', 'standard');
    $response->assertJsonFragment(['module_finance']);
    $response->assertJsonFragment(['academic_structure']);
    $response->assertJsonPath('licence.caps.max_active_students', 500);
    expect($response->json('licence.valid_until'))->toStartWith(today()->addYear()->toDateString());
    $response->assertJsonPath('directives', []);
});

test('heartbeat answers expired without any licence', function () {
    $deployment = Deployment::factory()->create();
    $token = $deployment->createToken('heartbeat', [Deployment::HEARTBEAT_ABILITY])->plainTextToken;

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/v1/heartbeats', heartbeatPayload($deployment));

    $response->assertOk();
    $response->assertJsonPath('licence.tier', 'expired');
    $response->assertJsonPath('licence.modules', []);
    $response->assertJsonPath('directives', []);
    expect($response->json('licence.valid_until'))->toStartWith(today()->toDateString());
});

test('heartbeat answers expired for a lapsed licence', function () {
    $deployment = Deployment::factory()->create();
    Licence::factory()->for($deployment)->expired()->create();
    $token = $deployment->createToken('heartbeat', [Deployment::HEARTBEAT_ABILITY])->plainTextToken;

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/v1/heartbeats', heartbeatPayload($deployment));

    $response->assertOk();
    $response->assertJsonPath('licence.tier', 'expired');
    $response->assertJsonPath('licence.modules', []);
});

test('price preview honours bands and the all-modules discount', function () {
    $preview = Licence::previewFor(['module_finance' => true, 'module_reports' => true], 200);

    expect($preview['currency'])->toBe('GHS');
    expect($preview['band'])->toBe('101 - 500 Students');
    expect($preview['multiplier'])->toBe(1.25);
    expect($preview['total'])->toBe((12000.00 + 2200.00 + 1400.00) * 1.25);
    expect($preview['discount'])->toBe(0.0);

    $allModules = array_fill_keys([
        'module_finance', 'module_staff_hr', 'module_reports', 'module_evaluations',
        'module_student_welfare', 'module_progression', 'module_system_admin',
        'module_teacher_tools', 'module_messaging', 'module_practicum',
    ], true);

    $discounted = Licence::previewFor($allModules, 50);

    $modulesTotal = 2200.00 + 1800.00 + 1400.00 + 1600.00 + 1500.00 + 1200.00 + 1000.00 + 900.00 + 1500.00 + 2000.00;

    expect($discounted['discount'])->toBe(round($modulesTotal * 0.20, 2));
    expect($discounted['total'])->toBe(round((12000.00 + $modulesTotal - $discounted['discount']) * 1.0, 2));
});

test('licence pages render for authenticated users', function () {
    $this->actingAs(User::factory()->create());
    $deployment = Deployment::factory()->create();

    $this->get(route('licences.index'))->assertOk();
    $this->get(route('licences.edit', $deployment->uuid))->assertOk();
});

test('catalogue seeder imports every feature key without touching existing rows', function () {
    expect(Feature::count())->toBe(19);

    expect(Feature::where('kind', 'core')->where('locked', true)->pluck('key')->all())
        ->toEqualCanonicalizing(['academic_structure', 'students', 'grading', 'teacher_portal', 'student_portal']);

    expect(Feature::where('kind', 'core')->where('locked', false)->pluck('key')->all())
        ->toEqualCanonicalizing(['core_timetable', 'core_attendance', 'core_memos', 'core_impersonation']);

    expect(Feature::where('kind', 'module')->pluck('key')->all())
        ->toEqualCanonicalizing([
            'module_finance', 'module_staff_hr', 'module_reports', 'module_evaluations',
            'module_student_welfare', 'module_progression', 'module_system_admin',
            'module_teacher_tools', 'module_messaging', 'module_practicum',
        ]);

    Feature::where('key', 'module_finance')->update(['label' => 'Custom label', 'active' => false]);

    $this->seed(CatalogueSeeder::class);

    expect(Feature::count())->toBe(19);
    expect(Feature::where('key', 'module_finance')->firstOrFail())
        ->label->toBe('Custom label')
        ->active->toBeFalse();
});

test('feature keys are immutable through mass assignment', function () {
    $feature = Feature::where('key', 'module_finance')->firstOrFail();

    $feature->update(['key' => 'module_hacked', 'label' => 'Changed label']);

    expect($feature->fresh()->key)->toBe('module_finance');
    expect($feature->fresh()->label)->toBe('Changed label');
});

test('renew writes a price snapshot at grant time', function () {
    $this->actingAs(User::factory()->create());
    $deployment = Deployment::factory()->create();
    Licence::factory()->for($deployment)->create([
        'modules' => ['module_reports' => true],
        'expires_at' => today()->addDays(10),
    ]);

    Feature::where('key', 'module_reports')->update(['base_price' => 9999.00]);

    Volt::test('pages.licences.edit', ['deployment' => $deployment])
        ->call('renew')
        ->assertHasNoErrors();

    $renewed = Licence::where('deployment_id', $deployment->id)->latest('id')->firstOrFail();
    $snapshot = $renewed->price_snapshot;

    expect($snapshot['currency'])->toBe('GHS');
    expect($snapshot['granted_modules'])->toBe(['module_reports']);

    $line = collect($snapshot['lines'])->firstWhere('label', 'Advanced Reports & Charts');

    expect((float) $line['amount'])->toBe(9999.00);
});

test('inactive features are excluded from heartbeat answers', function () {
    $deployment = Deployment::factory()->create();
    Licence::factory()->for($deployment)->create([
        'modules' => ['module_finance' => true],
        'expires_at' => today()->addYear(),
    ]);
    $token = $deployment->createToken('heartbeat', [Deployment::HEARTBEAT_ABILITY])->plainTextToken;

    Feature::where('key', 'module_finance')->update(['active' => false]);
    Feature::where('key', 'students')->update(['active' => false]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/v1/heartbeats', heartbeatPayload($deployment));

    $response->assertOk();
    expect($response->json('licence.modules'))->not->toContain('module_finance', 'students');
    expect($response->json('licence.modules'))->toContain('academic_structure');
});

test('catalogue is forbidden without the super-admin role', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('catalogue.index'))->assertForbidden();
});

test('catalogue redirects guests to login', function () {
    $this->get(route('catalogue.index'))->assertRedirect(route('login'));
});

test('catalogue renders for super-admins', function () {
    $this->actingAs(actingSuperAdmin());

    $this->get(route('catalogue.index'))
        ->assertOk()
        ->assertSee('Financial Portal');
});

test('catalogue updates prices and logs the change', function () {
    $this->actingAs(actingSuperAdmin());
    $feature = Feature::where('key', 'module_finance')->firstOrFail();

    Volt::test('pages.catalogue.index')
        ->call('edit', $feature->id)
        ->set('label', 'Finance Plus')
        ->set('base_price', '2400.50')
        ->call('save')
        ->assertHasNoErrors();

    expect($feature->fresh()->label)->toBe('Finance Plus');
    expect($feature->fresh()->key)->toBe('module_finance');
    $this->assertDatabaseHas('activity_log', ['description' => 'catalogue.updated']);
});

test('catalogue toggles offerings and logs the change', function () {
    $this->actingAs(actingSuperAdmin());
    $feature = Feature::where('key', 'module_reports')->firstOrFail();

    Volt::test('pages.catalogue.index')
        ->call('toggleActive', $feature->id)
        ->assertHasNoErrors();

    expect($feature->fresh()->active)->toBeFalse();
    $this->assertDatabaseHas('activity_log', ['description' => 'catalogue.toggled']);
});

test('catalogue creates a feature and logs it', function () {
    $this->actingAs(actingSuperAdmin());

    Volt::test('pages.catalogue.index')
        ->set('key', 'module_custom')
        ->set('label', 'Custom Module')
        ->set('description', 'Custom description.')
        ->set('kind', 'module')
        ->set('base_price', '1800')
        ->set('renewal_base', '450')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('features', [
        'key' => 'module_custom',
        'label' => 'Custom Module',
        'kind' => 'module',
    ]);
    $this->assertDatabaseHas('activity_log', ['description' => 'catalogue.created']);
});

test('catalogue rejects duplicate keys', function () {
    $this->actingAs(actingSuperAdmin());

    Volt::test('pages.catalogue.index')
        ->set('key', 'module_finance')
        ->set('label', 'Duplicate')
        ->set('kind', 'module')
        ->call('save')
        ->assertHasErrors(['key']);

    expect(Feature::where('key', 'module_finance')->count())->toBe(1);
});
