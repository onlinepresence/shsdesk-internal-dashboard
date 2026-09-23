<?php

namespace App\Livewire;

use App\Models\Feature;
use App\Models\Setting;
use App\Support\EnvWriter;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SetupPanel extends Component
{
    public function mount(): void
    {
        $this->ensureSuperAdmin();
    }

    /**
     * Whether any bootstrap gap remains. The panel renders only while
     * true, so a clean install never sees it.
     */
    #[Computed]
    public function shouldShow(): bool
    {
        return ! $this->appKeySet()
            || $this->signingKeyMissing()
            || ! $this->catalogueSeeded()
            || ! $this->settingsSeeded()
            || $this->mailFrom() === null;
    }

    #[Computed]
    public function signingKeyMissing(): bool
    {
        return empty(config('licence-export.signing_key'));
    }

    /**
     * Whether features and pricing globals are both seeded.
     */
    #[Computed]
    public function catalogueReady(): bool
    {
        return $this->catalogueSeeded() && $this->settingsSeeded();
    }

    /**
     * Effective sender address, or null when unconfigured. The framework
     * placeholder counts as unconfigured — invites would go nowhere real.
     */
    #[Computed]
    public function mailFrom(): ?string
    {
        $address = trim((string) config('mail.from.address', ''));

        if ($address === '' || $address === 'hello@example.com') {
            return null;
        }

        return $address;
    }

    /**
     * Generate the Ed25519 seed into .env — the same path the demo-keys
     * page uses. Full key UI lives there; this panel only links to it.
     */
    public function generateSigningKey(): void
    {
        $this->ensureSuperAdmin();

        $seed = bin2hex(random_bytes(32));

        if (! app(EnvWriter::class)->ensurePresent('LICENCE_SIGNING_KEY', $seed)) {
            $this->addError('signing_key', __('LICENCE_SIGNING_KEY is already set. Nothing was written.'));

            return;
        }

        config()->set('licence-export.signing_key', $seed);

        activity('demo')
            ->causedBy(Auth::user())
            ->log('demo.signing_key_generated');

        unset($this->signingKeyMissing, $this->shouldShow);

        session()->flash('status', __('Signing key generated.'));
    }

    /**
     * Seed catalogue features and pricing globals. Both seeders skip
     * existing rows, so re-running never clobbers admin edits.
     */
    public function seedCatalogueAndSettings(): void
    {
        $this->ensureSuperAdmin();

        (new CatalogueSeeder)->run();
        (new SettingsSeeder)->run();

        activity('setup')
            ->causedBy(Auth::user())
            ->log('setup.catalogue_seeded');

        unset($this->shouldShow, $this->catalogueReady);

        session()->flash('status', __('Catalogue and settings seeded.'));
    }

    public function render()
    {
        return view('livewire.setup-panel');
    }

    /**
     * This panel — and its actions — are super-admin only. Ability
     * holders without the role never reach it.
     */
    protected function ensureSuperAdmin(): void
    {
        abort_unless(Auth::user()?->hasRole('super-admin'), 403);
    }

    protected function appKeySet(): bool
    {
        return ! empty(config('app.key'));
    }

    protected function catalogueSeeded(): bool
    {
        return Feature::query()->exists();
    }

    protected function settingsSeeded(): bool
    {
        return Setting::query()->exists();
    }
}
