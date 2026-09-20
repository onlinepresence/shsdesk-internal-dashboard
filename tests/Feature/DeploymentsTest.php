<?php

use App\Models\Deployment;
use App\Models\EnrollmentCode;
use App\Models\Licence;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\ProductSeeder;
use Laravel\Sanctum\Sanctum;
use Livewire\Volt\Volt;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(ProductSeeder::class);
});

function heartbeatPayload(Deployment $deployment, array $overrides = []): array
{
    return array_merge([
        'deployment_uuid' => $deployment->uuid,
        'product' => 'flowedu',
        'app_version' => '1.2.0',
        'counts' => ['students' => 120, 'teachers' => 12, 'users' => 135],
        'modules_in_use' => ['attendance', 'grades'],
    ], $overrides);
}

function issueHeartbeatToken(Deployment $deployment, array $abilities = [Deployment::HEARTBEAT_ABILITY]): string
{
    return $deployment->createToken('heartbeat', $abilities)->plainTextToken;
}

test('deployments can be registered and the token is shown', function () {
    $this->actingAs(User::factory()->create());

    $component = Volt::test('pages.deployments.create')
        ->set('school_name', 'Springfield Elementary')
        ->set('product', 'flowedu')
        ->set('url', 'https://springfield.example.com')
        ->call('register');

    $component->assertHasNoErrors();

    $plainTextToken = $component->get('plainTextToken');

    expect($plainTextToken)->toBeString()->not->toBeEmpty();
    $component->assertSee($plainTextToken);

    $this->assertDatabaseHas('deployments', [
        'school_name' => 'Springfield Elementary',
        'product' => 'flowedu',
    ]);
    $this->assertDatabaseHas('activity_log', ['description' => 'deployment.registered']);
});

test('issued token value is only available on the registering component', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('pages.deployments.create')->assertSet('plainTextToken', null);
});

test('heartbeat rejects unauthenticated requests', function () {
    $deployment = Deployment::factory()->create();

    $this->postJson('/api/v1/heartbeats', heartbeatPayload($deployment))
        ->assertUnauthorized();
});

test('heartbeat rejects an unknown token', function () {
    $deployment = Deployment::factory()->create();

    $this->withHeaders(['Authorization' => 'Bearer invalid-token-value'])
        ->postJson('/api/v1/heartbeats', heartbeatPayload($deployment))
        ->assertUnauthorized();
});

test('heartbeat rejects a token issued for another deployment', function () {
    $reporter = Deployment::factory()->create();
    $other = Deployment::factory()->create();

    Sanctum::actingAs($reporter, [Deployment::HEARTBEAT_ABILITY]);

    $this->postJson('/api/v1/heartbeats', heartbeatPayload($other))
        ->assertForbidden();
});

test('heartbeat rejects a token without the heartbeat ability', function () {
    $deployment = Deployment::factory()->create();

    Sanctum::actingAs($deployment, []);

    $this->postJson('/api/v1/heartbeats', heartbeatPayload($deployment))
        ->assertForbidden();
});

test('heartbeat rejects revoked deployments', function () {
    $deployment = Deployment::factory()->revoked()->create();
    $token = issueHeartbeatToken($deployment);

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/v1/heartbeats', heartbeatPayload($deployment))
        ->assertForbidden();
});

test('heartbeat updates the deployment, stores a receipt, and answers licence terms', function () {
    $deployment = Deployment::factory()->create(['app_version' => '1.0.0', 'last_seen_at' => now()->subDay()]);
    $token = issueHeartbeatToken($deployment);

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/v1/heartbeats', heartbeatPayload($deployment));

    $response->assertOk();
    $response->assertJsonPath('licence.tier', 'expired');
    $response->assertJsonPath('licence.modules', []);
    $response->assertJsonPath('licence.caps', []);
    $response->assertJsonPath('directives', []);
    $response->assertJsonStructure(['licence' => ['tier', 'modules', 'caps', 'valid_until'], 'directives']);
    expect($response->json('licence.valid_until'))->toStartWith(today()->toDateString());

    expect($deployment->fresh()->app_version)->toBe('1.2.0');

    $this->assertDatabaseHas('deployment_heartbeats', [
        'deployment_id' => $deployment->id,
        'app_version' => '1.2.0',
        'students' => 120,
        'teachers' => 12,
        'users' => 135,
    ]);
    $this->assertDatabaseHas('activity_log', ['description' => 'heartbeat.received']);
});

test('issued token authenticates a real heartbeat', function () {
    $this->actingAs(User::factory()->create());

    $plainTextToken = Volt::test('pages.deployments.create')
        ->set('school_name', 'Shelbyville High')
        ->set('product', 'flowedu')
        ->set('url', 'https://shelbyville.example.com')
        ->call('register')
        ->get('plainTextToken');

    $deployment = Deployment::where('school_name', 'Shelbyville High')->firstOrFail();

    // Heartbeat callers never hold a web session; Sanctum prefers the
    // session user over the bearer token, so log out first.
    auth()->logout();

    $this->withHeaders(['Authorization' => "Bearer {$plainTextToken}"])
        ->postJson('/api/v1/heartbeats', heartbeatPayload($deployment))
        ->assertOk();
});

test('stale scope matches silent deployments only', function () {
    $fresh = Deployment::factory()->create();
    $stale = Deployment::factory()->stale()->create();
    $neverSeen = Deployment::factory()->neverSeen()->create();
    $revoked = Deployment::factory()->revoked()->stale()->create();

    $staleIds = Deployment::stale()->pluck('id')->all();

    expect($staleIds)->toContain($stale->id, $neverSeen->id)
        ->not->toContain($fresh->id, $revoked->id);

    expect($fresh->status)->toBe('active')
        ->and($stale->status)->toBe('stale')
        ->and($revoked->status)->toBe('revoked');
});

test('heartbeat rejects a product claim mismatching the deployment', function () {
    $deployment = Deployment::factory()->create(['product' => 'flowedu']);
    $other = Product::factory()->create(['active' => true]);
    $token = issueHeartbeatToken($deployment);

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/v1/heartbeats', heartbeatPayload($deployment, ['product' => $other->slug]))
        ->assertForbidden();
});

test('heartbeat rejects deployments on inactive products', function () {
    $deployment = Deployment::factory()->create(['product' => 'shsdesk']);
    $token = issueHeartbeatToken($deployment);

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/v1/heartbeats', heartbeatPayload($deployment, ['product' => 'shsdesk']))
        ->assertForbidden();
});

test('enroll rejects a mismatched product claim', function () {
    $deployment = Deployment::factory()->create(['product' => 'flowedu']);
    $other = Product::factory()->create(['active' => true]);
    $code = EnrollmentCode::mintFor($deployment)['code'];

    $this->postJson('/api/v1/enroll', [
        'code' => $code,
        'deployment_uuid' => $deployment->uuid,
        'product' => $other->slug,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'product_mismatch');
});

test('enroll rejects inactive products', function () {
    $deployment = Deployment::factory()->create(['product' => 'shsdesk']);
    $code = EnrollmentCode::mintFor($deployment)['code'];

    $this->postJson('/api/v1/enroll', ['code' => $code])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'product_inactive');
});

test('export marks file-managed and the first heartbeat clears it', function () {
    config()->set('licence-export.signing_key', str_repeat('a', 64));

    $this->actingAs(User::factory()->create());

    $deployment = Deployment::factory()->create();
    Licence::factory()->for($deployment)->create();

    $this->get(route('licences.export', $deployment->uuid))
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename="licence-'.$deployment->uuid.'.json"');

    expect($deployment->fresh()->isFileManaged())->toBeTrue();
    $this->assertDatabaseHas('activity_log', ['description' => 'licence.exported']);

    $this->get(route('deployments.show', $deployment))
        ->assertOk()
        ->assertSee('File-managed');

    $token = issueHeartbeatToken($deployment);

    // Heartbeat callers never hold a web session; Sanctum prefers the
    // session user over the bearer token, so log out first.
    auth()->logout();

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/v1/heartbeats', heartbeatPayload($deployment))
        ->assertOk();

    expect($deployment->fresh()->isFileManaged())->toBeFalse();
    $this->assertDatabaseHas('activity_log', ['description' => 'deployment.file_managed_cleared']);

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/v1/heartbeats', heartbeatPayload($deployment))
        ->assertOk();

    expect(Activity::where('description', 'deployment.file_managed_cleared')->count())->toBe(1);
});

test('stale scope excludes file-managed deployments', function () {
    $managed = Deployment::factory()->stale()->create(['file_managed_at' => now()->subDay()]);
    $stale = Deployment::factory()->stale()->create();

    $staleIds = Deployment::stale()->pluck('id')->all();

    expect($staleIds)->toContain($stale->id)->not->toContain($managed->id);
});

test('deployment pages render for authenticated users', function () {
    $this->actingAs(User::factory()->create());
    $deployment = Deployment::factory()->create();

    $this->get(route('deployments.index'))->assertOk();
    $this->get(route('deployments.create'))->assertOk();
    $this->get(route('deployments.show', $deployment))
        ->assertOk()
        ->assertSee($deployment->school_name);
});
