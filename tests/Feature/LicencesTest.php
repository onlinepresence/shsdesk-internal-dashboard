<?php

use App\Models\Deployment;
use App\Models\Feature;
use App\Models\Licence;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\SettingsSeeder;
use Livewire\Volt\Volt;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(CatalogueSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(ProductSeeder::class);
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

    expect(config('licence-catalogue.pricing'))->toBeNull();
    expect(config('licence-catalogue.student_pricing_bands'))->toBeNull();
    expect(array_keys(config('licence-catalogue.core_pricing')))->toBe(['1-500', '501-1000', '1001-2000', '2001-3500', '3500+']);
    expect(config('licence-catalogue.bundle_discount'))->toBe(0.12);
    expect(config('licence-catalogue.founding_client_discount'))->toBe(0.15);
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

test('price preview follows the live core pricing bands', function () {
    $preview = Licence::previewFor(['module_finance' => true, 'module_reports' => true], 200);

    expect($preview['currency'])->toBe('GHS');
    expect($preview['is_custom'])->toBeFalse();
    expect($preview['band_key'])->toBe('1-500');
    expect($preview['multiplier'])->toBe(1.0);
    expect($preview['core_upfront'])->toBe(4500.0);
    expect($preview['core_renewal'])->toBe(1200.0);
    expect($preview['apply_bundle'])->toBeFalse();
    expect($preview['bundle_discount_onetime'])->toBe(0.0);
    expect($preview['founding_discount_upfront'])->toBe(0.0);
    // Core 4500 + modules (2200 + 1400) + self-hosted setup 1200.
    expect($preview['upfront_total'])->toBe(9300.0);
    // Core renewal 1200 + modules renewal (550 + 350).
    expect($preview['renew_total'])->toBe(2100.0);
});

test('bundle discount applies at four selected modules, never below', function () {
    $three = Licence::previewFor([
        'module_finance' => true, 'module_staff_hr' => true, 'module_reports' => true,
    ], 200);

    expect($three['apply_bundle'])->toBeFalse();
    expect($three['bundle_discount_onetime'])->toBe(0.0);
    expect($three['bundle_discount_renew'])->toBe(0.0);

    $four = Licence::previewFor([
        'module_finance' => true, 'module_staff_hr' => true,
        'module_reports' => true, 'module_evaluations' => true,
    ], 200);

    expect($four['apply_bundle'])->toBeTrue();
    // (2200 + 1800 + 1400 + 1600) x 1.0 = 7000, less 12% = 6160.
    expect($four['modules_onetime_sum'])->toBe(7000.0);
    expect($four['bundle_discount_onetime'])->toBe(840.0);
    expect($four['modules_onetime_final'])->toBe(6160.0);
    // (550 + 450 + 350 + 400) x 1.0 = 1750, less 12% = 1540.
    expect($four['modules_renew_sum'])->toBe(1750.0);
    expect($four['bundle_discount_renew'])->toBe(210.0);
    expect($four['modules_renew_final'])->toBe(1540.0);
});

test('worked example matches the hand-computed FlowEdu quote', function () {
    // 600-student founding school, 4 modules, managed hosting,
    // migration plus one of each training.
    $preview = Licence::previewFor(
        [
            'module_finance' => true, 'module_staff_hr' => true,
            'module_reports' => true, 'module_evaluations' => true,
        ],
        600,
        null,
        [
            'hosting_mode' => 'managed',
            'config_setup' => false,
            'migration' => true,
            'training_admin' => 1,
            'training_teacher' => 1,
            'training_onsite' => 1,
            'founding_client' => true,
        ],
    );

    expect($preview['is_custom'])->toBeFalse();
    expect($preview['band_key'])->toBe('501-1000');
    expect($preview['multiplier'])->toBe(1.3);

    // Core 6500 / 1600, founding 15% off core: 975 / 240.
    expect($preview['core_upfront_final'])->toBe(5525.0);
    expect($preview['core_renewal_final'])->toBe(1360.0);
    expect($preview['founding_discount_upfront'])->toBe(975.0);
    expect($preview['founding_discount_renew'])->toBe(240.0);

    // Modules one-time 7000 x 1.3 = 9100, less 12% bundle = 8008.
    expect($preview['modules_onetime_final'])->toBe(8008.0);
    // Modules renewal 1750 x 1.3 = 2275, less 12% bundle = 2002.
    expect($preview['modules_renew_final'])->toBe(2002.0);

    expect($preview['hosting_setup_fee'])->toBe(1600.0);
    expect($preview['configuration_fee'])->toBe(2000.0);
    expect($preview['training_fee'])->toBe(2600.0);

    // Upfront: 5525 + 8008 + 1600 + 2000 + 2600 = 19733.
    expect($preview['upfront_total'])->toBe(19733.0);
    // Renewal: 1360 + 2002 = 3362.
    expect($preview['renew_total'])->toBe(3362.0);
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
    expect($snapshot['modules'][0]['key'])->toBe('module_reports');
    expect((float) $snapshot['modules'][0]['onetime'])->toBe(9999.00);
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

test('settings seeder imports live FlowEdu pricing defaults without touching existing rows', function () {
    expect(Setting::get(Setting::CURRENCY))->toBe('GHS');
    expect(Setting::get(Setting::BUNDLE_DISCOUNT_RATE))->toBe('0.12');
    expect(Setting::get(Setting::BUNDLE_THRESHOLD))->toBe('4');
    expect(Setting::get(Setting::FOUNDING_DISCOUNT_RATE))->toBe('0.15');
    expect(Setting::get(Setting::HOSTING_SELF_HOSTED_FEE))->toBe('1200');
    expect(Setting::get(Setting::HOSTING_MANAGED_FEE))->toBe('1600');
    expect(Setting::get(Setting::HOSTING_NONE_FEE))->toBe('0');
    expect(Setting::get(Setting::CONFIG_SETUP_FEE))->toBe('800');
    expect(Setting::get(Setting::MIGRATION_FEE))->toBe('2000');
    expect(Setting::get(Setting::TRAINING_ADMIN_RATE))->toBe('600');
    expect(Setting::get(Setting::TRAINING_TEACHER_RATE))->toBe('500');
    expect(Setting::get(Setting::TRAINING_ONSITE_RATE))->toBe('1500');
    expect(Setting::corePricing())->toBe([
        ['key' => '1-500', 'label' => '1 – 500 students', 'min' => 1, 'max' => 500, 'core_upfront' => 4500.0, 'core_renewal' => 1200.0, 'multiplier' => 1.0, 'custom' => false],
        ['key' => '501-1000', 'label' => '501 – 1,000 students', 'min' => 501, 'max' => 1000, 'core_upfront' => 6500.0, 'core_renewal' => 1600.0, 'multiplier' => 1.3, 'custom' => false],
        ['key' => '1001-2000', 'label' => '1,001 – 2,000 students', 'min' => 1001, 'max' => 2000, 'core_upfront' => 9000.0, 'core_renewal' => 2200.0, 'multiplier' => 1.6, 'custom' => false],
        ['key' => '2001-3500', 'label' => '2,001 – 3,500 students', 'min' => 2001, 'max' => 3500, 'core_upfront' => 12500.0, 'core_renewal' => 3000.0, 'multiplier' => 2.0, 'custom' => false],
        ['key' => '3500+', 'label' => '3,500+ students', 'min' => 3501, 'max' => null, 'core_upfront' => 0.0, 'core_renewal' => 0.0, 'multiplier' => 1.0, 'custom' => true],
    ]);

    Setting::set(Setting::BUNDLE_DISCOUNT_RATE, '0.5');

    $this->seed(SettingsSeeder::class);

    expect(Setting::get(Setting::BUNDLE_DISCOUNT_RATE))->toBe('0.5');
});

test('catalogue updates prices and logs the change', function () {
    $this->actingAs(actingSuperAdmin());
    $feature = Feature::where('key', 'module_finance')->firstOrFail();

    Volt::test('pages.catalogue.index')
        ->call('edit', $feature->id)
        ->set('label', 'Finance Plus')
        ->set('base_price', '2400.50')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    expect($feature->fresh()->label)->toBe('Finance Plus');
    expect($feature->fresh()->key)->toBe('module_finance');
    $this->assertDatabaseHas('activity_log', ['description' => 'catalogue.updated']);
});

test('catalogue toggles offerings and logs the change', function () {
    $this->actingAs(actingSuperAdmin());
    $feature = Feature::where('key', 'module_reports')->firstOrFail();

    Volt::test('pages.catalogue.index')
        ->call('toggleActive', $feature->id)
        ->assertHasNoErrors()
        ->assertDispatched('toast');

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
        ->assertHasNoErrors()
        ->assertDispatched('toast');

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

test('pricing globals save from settings and log the diff', function () {
    $this->actingAs(actingSuperAdmin());

    Volt::test('pages.catalogue.index')
        ->set('currency', 'GHS')
        ->set('founding_rate', '0.2')
        ->set('bundle_rate', '0.1')
        ->set('bundle_threshold', 3)
        ->set('core_rows.0.core_upfront', '4600')
        ->set('hosting_managed', '1700')
        ->set('training_onsite', '1600')
        ->call('saveGlobals')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    expect(Setting::get(Setting::FOUNDING_DISCOUNT_RATE))->toBe('0.2');
    expect(Setting::get(Setting::BUNDLE_DISCOUNT_RATE))->toBe('0.1');
    expect(Setting::get(Setting::BUNDLE_THRESHOLD))->toBe('3');
    expect(Setting::get(Setting::HOSTING_MANAGED_FEE))->toBe('1700');
    expect(Setting::get(Setting::TRAINING_ONSITE_RATE))->toBe('1600');
    expect(Setting::corePricing()[0]['core_upfront'])->toBe(4600.0);
    expect(Setting::corePricing())->toHaveCount(5);
    expect(Setting::corePricing()[4]['custom'])->toBeTrue();

    $logged = Activity::where('description', 'settings.updated')->latest('id')->firstOrFail();
    $properties = $logged->properties->toArray();

    expect($properties['before']['bundle_discount_rate'])->toBe('0.12');
    expect($properties['after']['bundle_discount_rate'])->toBe('0.1');
    expect($properties['after']['hosting_managed_fee'])->toBe('1700');
});

test('pricing globals reject out-of-range discount rates', function () {
    $this->actingAs(actingSuperAdmin());

    Volt::test('pages.catalogue.index')
        ->set('bundle_rate', '1.5')
        ->call('saveGlobals')
        ->assertHasErrors(['bundle_rate']);

    expect(Setting::get(Setting::BUNDLE_DISCOUNT_RATE))->toBe('0.12');

    Volt::test('pages.catalogue.index')
        ->set('founding_rate', '2')
        ->call('saveGlobals')
        ->assertHasErrors(['founding_rate']);

    expect(Setting::get(Setting::FOUNDING_DISCOUNT_RATE))->toBe('0.15');
});

test('preview splits upfront and renewal with founding off the core only', function () {
    $preview = Licence::previewFor(['module_finance' => true], 600, null, [
        'hosting_mode' => 'managed',
        'config_setup' => true,
        'migration' => true,
        'training_admin' => 2,
        'training_teacher' => 1,
        'training_onsite' => 0,
        'founding_client' => true,
    ]);

    expect($preview['band_key'])->toBe('501-1000');
    expect($preview['founding_discount_upfront'])->toBe(975.0);
    expect($preview['founding_discount_renew'])->toBe(240.0);
    expect($preview['apply_bundle'])->toBeFalse();
    expect($preview['hosting_setup_fee'])->toBe(1600.0);
    expect($preview['configuration_fee'])->toBe(2800.0);
    expect($preview['training_fee'])->toBe(1700.0);
    expect(collect($preview['trainings'])->pluck('label')->all())->toBe([
        'Remote Admin Training (2 sessions)',
        'Remote Lecturer Training (1 sessions)',
    ]);

    // Upfront: 5525 + 2860 + 1600 + 2800 + 1700 = 14485.
    expect($preview['upfront_total'])->toBe(14485.0);
    // Renewal: 1360 + 715 = 2075.
    expect($preview['renew_total'])->toBe(2075.0);
});

test('preview validation rejects unknown hosting modes and negative counts', function () {
    $this->actingAs(User::factory()->create());
    $deployment = Deployment::factory()->create();

    Volt::test('pages.licences.edit', ['deployment' => $deployment])
        ->set('hosting_mode', 'mars')
        ->set('training_admin', '-1')
        ->call('save')
        ->assertHasErrors(['hosting_mode', 'training_admin']);

    expect(Licence::where('deployment_id', $deployment->id)->count())->toBe(0);
});

test('save writes quote columns and snapshot with the full quote', function () {
    $this->actingAs(User::factory()->create());
    $deployment = Deployment::factory()->create();

    Volt::test('pages.licences.edit', ['deployment' => $deployment])
        ->set('modules', ['module_finance'])
        ->set('max_students', 500)
        ->set('starts_at', today()->toDateString())
        ->set('expires_at', today()->addYear()->toDateString())
        ->set('hosting_mode', 'managed')
        ->set('config_setup', true)
        ->set('migration', true)
        ->set('training_teacher', 3)
        ->set('founding_client', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('licences.index'));

    $licence = Licence::where('deployment_id', $deployment->id)->firstOrFail();

    expect($licence->hosting_mode)->toBe('managed');
    expect($licence->config_setup)->toBeTrue();
    expect($licence->migration)->toBeTrue();
    expect($licence->training_teacher)->toBe(3);
    expect($licence->founding_client)->toBeTrue();

    $snapshot = $licence->price_snapshot;

    // Band 1-500: core 4500 - 675 founding + finance 2200 + managed 1600
    // + config 800 + migration 2000 + teacher 3 x 500 = 11925 upfront.
    expect((float) $snapshot['upfront_total'])->toBe(11925.0);
    // Renewal: 1020 + 550 = 1570.
    expect((float) $snapshot['renew_total'])->toBe(1570.0);
    expect((float) $snapshot['founding_discount_upfront'])->toBe(675.0);
    expect($snapshot['quote']['hosting_mode'])->toBe('managed');
    expect($snapshot['quote']['config_setup'])->toBeTrue();
    expect($snapshot['quote']['migration'])->toBeTrue();
    expect($snapshot['quote']['training_teacher'])->toBe(3);
    expect($snapshot['quote']['founding_client'])->toBeTrue();
});

test('renew carries quote columns forward with a fresh snapshot', function () {
    $this->actingAs(User::factory()->create());
    $deployment = Deployment::factory()->create();
    Licence::factory()->for($deployment)->create([
        'modules' => ['module_reports' => true],
        'hosting_mode' => 'managed',
        'config_setup' => true,
        'migration' => true,
        'training_admin' => 2,
        'founding_client' => true,
        'expires_at' => today()->addDays(10),
    ]);

    Volt::test('pages.licences.edit', ['deployment' => $deployment])
        ->call('renew')
        ->assertHasNoErrors();

    $renewed = Licence::where('deployment_id', $deployment->id)->latest('id')->firstOrFail();

    expect($renewed->hosting_mode)->toBe('managed');
    expect($renewed->config_setup)->toBeTrue();
    expect($renewed->migration)->toBeTrue();
    expect($renewed->training_admin)->toBe(2);
    expect($renewed->founding_client)->toBeTrue();
    expect((float) $renewed->price_snapshot['founding_discount_upfront'])->toBe(675.0);
    expect($renewed->price_snapshot['quote']['hosting_mode'])->toBe('managed');
    expect($renewed->price_snapshot['quote']['config_setup'])->toBeTrue();
});

test('price snapshot captures every quote dimension', function () {
    $snapshot = Licence::priceSnapshot(
        [
            'module_finance' => true, 'module_staff_hr' => true,
            'module_reports' => true, 'module_evaluations' => true,
        ],
        600,
        null,
        [
            'hosting_mode' => 'managed',
            'config_setup' => true,
            'migration' => true,
            'training_admin' => 1,
            'training_teacher' => 2,
            'training_onsite' => 0,
            'founding_client' => true,
        ],
    );

    expect($snapshot)->toHaveKeys([
        'is_custom', 'band_key', 'band_label', 'multiplier', 'currency',
        'core_upfront', 'core_renewal', 'core_upfront_final', 'core_renewal_final',
        'apply_founding', 'founding_discount_rate', 'founding_discount_upfront', 'founding_discount_renew',
        'modules', 'modules_onetime_sum', 'modules_renew_sum', 'modules_onetime_final', 'modules_renew_final',
        'apply_bundle', 'bundle_discount_rate', 'bundle_discount_onetime', 'bundle_discount_renew',
        'hosting_setup', 'hosting_label', 'hosting_setup_fee',
        'addons', 'configuration_fee',
        'trainings', 'training_fee', 'admin_training_qty', 'teacher_training_qty', 'onsite_training_qty',
        'upfront_total', 'renew_total', 'max_students', 'reported_students',
        'granted_modules', 'quote',
    ]);
    expect($snapshot['quote'])->toBe([
        'hosting_mode' => 'managed',
        'config_setup' => true,
        'migration' => true,
        'training_admin' => 1,
        'training_teacher' => 2,
        'training_onsite' => 0,
        'founding_client' => true,
    ]);
    expect($snapshot['granted_modules'])->toEqualCanonicalizing([
        'module_finance', 'module_staff_hr', 'module_reports', 'module_evaluations',
    ]);
    expect($snapshot['max_students'])->toBe(600);
    // Upfront: 5525 + 8008 + 1600 + 2800 + 1600 = 19533.
    expect($snapshot['upfront_total'])->toBe(19533.0);
    expect($snapshot['renew_total'])->toBe(1360.0 + 2002.0);
});

test('null cap bands from reported heartbeat students', function () {
    $preview = Licence::previewFor(['module_finance' => true], null, 650);

    expect($preview['is_custom'])->toBeFalse();
    expect($preview['band_key'])->toBe('501-1000');
    expect($preview['band_label'])->toBe('650 students (reported)');
    expect($preview['multiplier'])->toBe(1.3);
    // Core 6500 + finance 2860 + self-hosted setup 1200.
    expect($preview['upfront_total'])->toBe(10560.0);
    // Core renewal 1600 + finance renewal 715.
    expect($preview['renew_total'])->toBe(2315.0);
});

test('null cap without heartbeats stays unmultiplied', function () {
    $preview = Licence::previewFor(['module_finance' => true], null, null);

    expect($preview['is_custom'])->toBeFalse();
    expect($preview['band_label'])->toBe('No student cap');
    expect($preview['multiplier'])->toBe(1.0);
    // First-band figures: core 4500 + finance 2200 + self-hosted setup 1200.
    expect($preview['upfront_total'])->toBe(7900.0);
    expect($preview['renew_total'])->toBe(1750.0);
});

test('explicit cap wins over reported students', function () {
    $preview = Licence::previewFor(['module_finance' => true], 50, 5000);

    expect($preview['is_custom'])->toBeFalse();
    expect($preview['band_key'])->toBe('1-500');
    expect($preview['band_label'])->toBe('1 – 500 students');
    expect($preview['multiplier'])->toBe(1.0);
});

test('counts past the auto-priced bands resolve to a custom quote', function () {
    $reported = Licence::previewFor(['module_finance' => true], null, 4000);

    expect($reported['is_custom'])->toBeTrue();
    expect($reported['band_key'])->toBe('3500+');
    expect($reported['upfront_total'])->toBeNull();
    expect($reported['renew_total'])->toBeNull();

    $capped = Licence::previewFor(['module_finance' => true], 3600);

    expect($capped['is_custom'])->toBeTrue();
    expect($capped['upfront_total'])->toBeNull();
    expect($capped['renew_total'])->toBeNull();
});

test('custom quotes still snapshot every dimension', function () {
    $snapshot = Licence::priceSnapshot(['module_finance' => true], null, 4000, [
        'hosting_mode' => 'managed',
        'founding_client' => true,
    ]);

    expect($snapshot['is_custom'])->toBeTrue();
    expect($snapshot['band_key'])->toBe('3500+');
    expect($snapshot['upfront_total'])->toBeNull();
    expect($snapshot['granted_modules'])->toBe(['module_finance']);
    expect($snapshot['quote']['hosting_mode'])->toBe('managed');
    expect($snapshot['quote']['founding_client'])->toBeTrue();
});

test('edit page previews the reported band for uncapped licences', function () {
    $this->actingAs(User::factory()->create());
    $deployment = Deployment::factory()->create();
    $deployment->heartbeats()->create([
        'app_version' => '1.0.0',
        'students' => 650,
        'teachers' => 10,
        'users' => 660,
        'modules_in_use' => [],
    ]);

    $preview = Volt::test('pages.licences.edit', ['deployment' => $deployment])->get('preview');

    expect($preview['band_label'])->toBe('650 students (reported)');
});

test('edit page shows the custom quote state past the auto-priced bands', function () {
    $this->actingAs(User::factory()->create());
    $deployment = Deployment::factory()->create();
    $deployment->heartbeats()->create([
        'app_version' => '1.0.0',
        'students' => 4000,
        'teachers' => 10,
        'users' => 4010,
        'modules_in_use' => [],
    ]);

    Volt::test('pages.licences.edit', ['deployment' => $deployment])
        ->assertOk()
        ->assertSee('Custom quote required');
});

test('licence created, updated, and renewed logs carry old and new values', function () {
    $this->actingAs(User::factory()->create());
    $deployment = Deployment::factory()->create();

    Volt::test('pages.licences.edit', ['deployment' => $deployment])
        ->set('modules', ['module_finance'])
        ->set('max_students', 500)
        ->set('starts_at', today()->toDateString())
        ->set('expires_at', today()->addYear()->toDateString())
        ->call('save')
        ->assertHasNoErrors();

    $created = Activity::where('description', 'licence.created')->latest('id')->firstOrFail();
    $createdProperties = $created->properties->toArray();

    expect($createdProperties['old'])->toBeNull();
    expect($createdProperties['new']['modules'])->toBe(['module_finance' => true]);

    Volt::test('pages.licences.edit', ['deployment' => $deployment])
        ->set('max_students', 750)
        ->set('config_setup', true)
        ->set('hosting_mode', 'managed')
        ->call('save')
        ->assertHasNoErrors();

    $updated = Activity::where('description', 'licence.updated')->latest('id')->firstOrFail();
    $updatedProperties = $updated->properties->toArray();

    expect($updatedProperties['old']['caps'])->toBe(['max_active_students' => 500]);
    expect($updatedProperties['new']['caps'])->toBe(['max_active_students' => 750]);
    expect($updatedProperties['old']['config_setup'])->toBeFalse();
    expect($updatedProperties['new']['config_setup'])->toBeTrue();
    expect($updatedProperties['old']['hosting_mode'])->toBe('self_hosted');
    expect($updatedProperties['new']['hosting_mode'])->toBe('managed');

    Volt::test('pages.licences.edit', ['deployment' => $deployment])
        ->call('renew')
        ->assertHasNoErrors();

    $renewed = Activity::where('description', 'licence.renewed')->latest('id')->firstOrFail();
    $renewedProperties = $renewed->properties->toArray();

    expect($renewedProperties['old'])->toHaveKey('licence_id');
    expect($renewedProperties['new'])->toHaveKey('licence_id');
    expect($renewedProperties['new']['licence_id'])->not->toBe($renewedProperties['old']['licence_id']);
});
