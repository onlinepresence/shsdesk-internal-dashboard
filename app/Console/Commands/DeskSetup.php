<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\EnvWriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

#[Signature('desk:setup')]
#[Description('Bootstrap a fresh ControlDesk install, filling only gaps')]
class DeskSetup extends Command
{
    /**
     * Check every bootstrap requirement in order, filling only gaps.
     * Non-interactive runs report without changing anything.
     */
    public function handle(): int
    {
        $reportOnly = ! $this->input->isInteractive();
        $gaps = 0;

        $this->info('ControlDesk setup'.($reportOnly ? ' (report-only)' : ''));

        if (! $this->checkAppKey($reportOnly)) {
            $gaps++;
        }

        if (! $this->checkSigningKey($reportOnly)) {
            $gaps++;
        }

        if (! $this->checkDatabase($reportOnly)) {
            $gaps++;
        }

        if (! $this->checkOwner($reportOnly)) {
            $gaps++;
        }

        if (! $this->checkStorageLink($reportOnly)) {
            $gaps++;
        }

        if ($reportOnly && $gaps > 0) {
            $this->warn("{$gaps} gap(s) remain; nothing was changed.");
        }

        return $reportOnly && $gaps > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * True when the step is satisfied (or was just filled).
     */
    protected function checkAppKey(bool $reportOnly): bool
    {
        if (! empty(config('app.key'))) {
            $this->stepDone('APP_KEY set');

            return true;
        }

        if ($reportOnly || ! $this->confirm('APP_KEY is missing. Generate one?', default: true)) {
            $this->stepSkipped('APP_KEY', $reportOnly ? 'missing' : 'declined');

            return false;
        }

        $this->call('key:generate', ['--force' => true]);
        $this->stepDone('APP_KEY generated');

        return true;
    }

    /**
     * True when the signing seed is effectively set (real environment
     * or .env alike). Only ever appends missing keys to the file.
     */
    protected function checkSigningKey(bool $reportOnly): bool
    {
        if (! empty(config('licence-export.signing_key'))) {
            $this->stepDone('LICENCE_SIGNING_KEY set');

            return true;
        }

        if ($reportOnly || ! $this->confirm('LICENCE_SIGNING_KEY is missing. Generate one into .env?', default: true)) {
            $this->stepSkipped('LICENCE_SIGNING_KEY', $reportOnly ? 'missing' : 'declined');

            return false;
        }

        $written = app(EnvWriter::class)->ensurePresent('LICENCE_SIGNING_KEY', bin2hex(random_bytes(32)));

        if (! $written) {
            $this->stepSkipped('LICENCE_SIGNING_KEY', 'already present on disk');

            return false;
        }

        activity('demo')->log('demo.signing_key_generated');
        $this->stepDone('LICENCE_SIGNING_KEY generated');

        return true;
    }

    /**
     * Report reachability, then migrate only when asked.
     */
    protected function checkDatabase(bool $reportOnly): bool
    {
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->stepSkipped('Database', 'unreachable');

            return false;
        }

        if ($reportOnly || ! $this->confirm('Database is reachable. Run migrations?', default: true)) {
            $this->stepSkipped('Database migrations', $reportOnly ? 'not run (report-only)' : 'declined');

            return true;
        }

        $this->call('migrate', ['--force' => true]);
        $this->stepDone('Database migrated');

        return true;
    }

    /**
     * Create the owner account when no users exist yet.
     */
    protected function checkOwner(bool $reportOnly): bool
    {
        try {
            $empty = User::query()->count() === 0;
        } catch (\Throwable $e) {
            $this->stepSkipped('Owner account', 'users table unavailable — migrate first');

            return false;
        }

        if (! $empty) {
            $this->stepDone('Owner account present');

            return true;
        }

        if ($reportOnly) {
            $this->stepSkipped('Owner account', 'no users yet');

            return false;
        }

        $attributes = $this->askOwnerAttributes();

        if ($attributes === null) {
            $this->stepSkipped('Owner account', 'aborted');

            return true;
        }

        $user = User::query()->create([
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'email_verified_at' => now(),
            'password' => Hash::make($attributes['password']),
        ]);

        $user->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));
        $this->stepDone("Owner account created ({$user->email})");

        return true;
    }

    /**
     * Prompt for owner credentials, retrying on validation errors.
     * An empty email aborts the step.
     *
     * @return array{name: string, email: string, password: string}|null
     */
    protected function askOwnerAttributes(): ?array
    {
        while (true) {
            $email = trim((string) $this->ask('Owner email (empty to skip)'));

            if ($email === '') {
                return null;
            }

            $name = trim((string) $this->ask('Owner name', explode('@', $email)[0]));
            $password = (string) $this->secret('Owner password (min 8 characters)');
            $confirm = (string) $this->secret('Confirm password');

            $validator = Validator::make(
                ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirm],
                [
                    'name' => ['required', 'string', 'max:255'],
                    'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                    'password' => ['required', 'string', 'min:8', 'confirmed'],
                ]
            );

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $message) {
                    $this->error($message);
                }

                continue;
            }

            /** @var array{name: string, email: string, password: string} */
            $attributes = $validator->validated();

            return ['name' => $attributes['name'], 'email' => $attributes['email'], 'password' => $attributes['password']];
        }
    }

    /**
     * Link public storage when the symlink is missing.
     */
    protected function checkStorageLink(bool $reportOnly): bool
    {
        if (is_link(public_path('storage'))) {
            $this->stepDone('Storage linked');

            return true;
        }

        if ($reportOnly || ! $this->confirm('public/storage link is missing. Create it?', default: true)) {
            $this->stepSkipped('Storage link', $reportOnly ? 'missing' : 'declined');

            return false;
        }

        $this->call('storage:link');
        $this->stepDone('Storage linked');

        return true;
    }

    protected function stepDone(string $label): void
    {
        $this->components->twoColumnDetail($label, '<info>DONE</info>');
    }

    protected function stepSkipped(string $label, string $reason): void
    {
        $this->components->twoColumnDetail($label, "<comment>SKIP ({$reason})</comment>");
    }
}
