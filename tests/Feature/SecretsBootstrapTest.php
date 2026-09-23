<?php

use App\Models\DemoKey;
use App\Models\Deployment;
use App\Models\EnrollmentCode;
use App\Models\Licence;
use App\Models\Product;
use App\Models\User;
use App\Support\EnvWriter;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

function makeSecretsAdmin(): User
{
    $user = User::factory()->create();

    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $user->assignRole('super-admin');

    return $user;
}

function tempEnvWriter(string $contents = ''): EnvWriter
{
    $path = tempnam(sys_get_temp_dir(), 'deskenv');

    file_put_contents($path, $contents);

    return new EnvWriter($path);
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'deskenv*') ?: [] as $file) {
        @unlink($file);
    }
});

test('demo-keys page shows gated banner without actions when unset', function () {
    config()->set('licence-export.signing_key', null);

    $this->actingAs(User::factory()->create());

    $this->get(route('demo-keys.index'))->assertForbidden();

    $this->actingAs(makeSecretsAdmin());

    $this->get(route('demo-keys.index'))
        ->assertOk()
        ->assertSee('Signing key missing')
        ->assertSee('LICENCE_SIGNING_KEY, which is not set')
        ->assertSee('Generate signing key');
});

test('mint is refused when the signing key is unset', function () {
    config()->set('licence-export.signing_key', null);

    $this->actingAs(makeSecretsAdmin());

    Volt::test('pages.demo-keys.index')
        ->set('label', 'Prospect')
        ->call('mint')
        ->assertHasErrors(['signing_key']);

    $this->assertDatabaseMissing('demo_keys', ['label' => 'Prospect']);
});

test('demo download refuses with 422 when the signing key is unset', function () {
    config()->set('licence-export.signing_key', null);

    $this->actingAs(makeSecretsAdmin());

    $key = DemoKey::factory()->create();

    $this->get(route('demo-keys.download', $key))->assertStatus(422);
});

test('generate action is forbidden without the catalogue permission', function () {
    config()->set('licence-export.signing_key', null);

    $this->actingAs(User::factory()->create());

    // Bare users are denied at mount, before any action runs.
    Volt::test('pages.demo-keys.index')->assertForbidden();
});

test('generate, mint and verify round-trip without ever displaying the seed', function () {
    config()->set('licence-export.signing_key', null);

    $writer = tempEnvWriter();
    $this->app->instance(EnvWriter::class, $writer);

    $this->actingAs(makeSecretsAdmin());

    Volt::test('pages.demo-keys.index')
        ->call('generateSigningKey')
        ->assertHasNoErrors();

    $seed = $writer->readValue('LICENCE_SIGNING_KEY');

    expect($seed)->toBeString()->toHaveLength(64);

    $this->get(route('demo-keys.index'))
        ->assertOk()
        ->assertDontSee($seed)
        ->assertSee('Show verification key');

    $code = Volt::test('pages.demo-keys.index')
        ->set('label', 'Round trip')
        ->set('expires_at', now()->addDay()->format('Y-m-d\TH:i'))
        ->call('mint')
        ->assertHasNoErrors()
        ->get('plainTextCode');

    $response = $this->postJson('/api/v1/demo-keys/verify', ['code' => $code])->assertOk();

    $document = $response->json();

    expect($document['algorithm'])->toBe('ed25519');
    expect(sodium_crypto_sign_verify_detached(
        hex2bin($document['signature']),
        Licence::canonicalExportJson($document['payload']),
        hex2bin(Licence::verificationKeyHex())
    ))->toBeTrue();

    $this->assertDatabaseHas('activity_log', ['description' => 'demo.signing_key_generated']);
});

test('generate never overwrites an already-set value', function () {
    $existing = str_repeat('a', 64);
    $writer = tempEnvWriter("LICENCE_SIGNING_KEY={$existing}\n");
    $this->app->instance(EnvWriter::class, $writer);

    config()->set('licence-export.signing_key', $existing);

    $this->actingAs(makeSecretsAdmin());

    Volt::test('pages.demo-keys.index')
        ->call('generateSigningKey')
        ->assertHasErrors(['signing_key']);

    expect($writer->readValue('LICENCE_SIGNING_KEY'))->toBe($existing);
});

test('desk setup reports gaps without changing anything in report mode', function () {
    config()->set('app.key', '');
    config()->set('licence-export.signing_key', null);

    $this->app->instance(EnvWriter::class, tempEnvWriter());

    $this->artisan('desk:setup', ['--no-interaction' => true])
        ->assertExitCode(1);

    expect(User::query()->count())->toBe(0);
});

test('desk setup treats a real-environment value as set and writes nothing', function () {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    config()->set('licence-export.signing_key', str_repeat('d', 64));

    User::factory()->create();

    $writer = tempEnvWriter("APP_KEY=from-file\n");
    $this->app->instance(EnvWriter::class, $writer);

    $this->artisan('desk:setup', ['--no-interaction' => true])
        ->expectsOutputToContain('SKIP');

    expect($writer->readValue('LICENCE_SIGNING_KEY'))->toBeNull();
});

test('desk setup repeat runs are stable no-ops', function () {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    config()->set('licence-export.signing_key', str_repeat('d', 64));

    User::factory()->create();

    $this->app->instance(EnvWriter::class, tempEnvWriter());

    $run = function () {
        $pending = $this->artisan('desk:setup')
            ->expectsConfirmation('Database is reachable. Run migrations?', 'no');

        if (! is_link(public_path('storage'))) {
            $pending->expectsConfirmation('public/storage link is missing. Create it?', 'no');
        }

        $pending->assertExitCode(0);
    };

    $run();
    $run();

    expect(User::query()->count())->toBe(1);
});

test('convert turns a demo key into a live deployment with a shown-once claim code', function () {
    $this->actingAs(makeSecretsAdmin());

    $product = Product::factory()->create(['slug' => 'flowedu']);
    $key = DemoKey::factory()->create();

    $token = Volt::test('pages.demo-keys.index')
        ->call('openConvertModal', $key->id)
        ->set('convert_school_name', 'Riverside College')
        ->set('convert_product', $product->slug)
        ->call('convert')
        ->assertHasNoErrors()
        ->assertSee('View deployment')
        ->get('convert_plainTextToken');

    expect($token)->toBeString()->not->toBeEmpty();

    $deployment = Deployment::where('school_name', 'Riverside College')->firstOrFail();

    expect($deployment->product)->toBe($product->slug);

    $code = EnrollmentCode::where('deployment_id', $deployment->id)->firstOrFail();

    expect(EnrollmentCode::hashCode($token))->toBe($code->code_hash);
    expect($key->fresh()->converted_deployment_id)->toBe($deployment->id);
    expect($key->fresh()->revoked_at)->not->toBeNull();

    $this->assertDatabaseHas('activity_log', ['description' => 'demo.converted']);
});

test('convert works on expired keys', function () {
    $this->actingAs(makeSecretsAdmin());

    $product = Product::factory()->create(['slug' => 'flowedu']);
    $key = DemoKey::factory()->create(['expires_at' => now()->subDay()]);

    Volt::test('pages.demo-keys.index')
        ->call('openConvertModal', $key->id)
        ->set('convert_school_name', 'Old Prospect College')
        ->set('convert_product', $product->slug)
        ->call('convert')
        ->assertHasNoErrors();

    $deployment = Deployment::where('school_name', 'Old Prospect College')->firstOrFail();

    expect($key->fresh()->converted_deployment_id)->toBe($deployment->id);
});

test('converted keys refuse a second conversion', function () {
    $this->actingAs(makeSecretsAdmin());

    $product = Product::factory()->create(['slug' => 'flowedu']);
    $key = DemoKey::factory()->create();

    Volt::test('pages.demo-keys.index')
        ->call('openConvertModal', $key->id)
        ->set('convert_school_name', 'Riverside College')
        ->set('convert_product', $product->slug)
        ->call('convert')
        ->assertHasNoErrors();

    Volt::test('pages.demo-keys.index')
        ->call('openConvertModal', $key->id)
        ->assertHasErrors(['convert']);

    expect(Deployment::query()->count())->toBe(1);
    expect(EnrollmentCode::query()->count())->toBe(1);
});

test('a failed conversion creates nothing', function () {
    $this->actingAs(makeSecretsAdmin());

    $product = Product::factory()->create(['slug' => 'flowedu']);
    $key = DemoKey::factory()->create();

    Deployment::creating(function () {
        throw new RuntimeException('boom');
    });

    try {
        expect(fn () => Volt::test('pages.demo-keys.index')
            ->call('openConvertModal', $key->id)
            ->set('convert_school_name', 'Riverside College')
            ->set('convert_product', $product->slug)
            ->call('convert'))->toThrow('boom');
    } finally {
        Deployment::clearBootedModels();
    }

    expect(Deployment::query()->count())->toBe(0);
    expect(EnrollmentCode::query()->count())->toBe(0);
    expect($key->fresh()->converted_deployment_id)->toBeNull();
    expect($key->fresh()->revoked_at)->toBeNull();
});

test('revoked but unconverted keys still convert', function () {
    $this->actingAs(makeSecretsAdmin());

    $product = Product::factory()->create(['slug' => 'flowedu']);
    $key = DemoKey::factory()->revoked()->create();

    Volt::test('pages.demo-keys.index')
        ->call('openConvertModal', $key->id)
        ->set('convert_school_name', 'Riverside College')
        ->set('convert_product', $product->slug)
        ->call('convert')
        ->assertHasNoErrors();

    $deployment = Deployment::where('school_name', 'Riverside College')->firstOrFail();

    expect($key->fresh()->converted_deployment_id)->toBe($deployment->id);
});
