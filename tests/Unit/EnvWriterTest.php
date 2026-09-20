<?php

use App\Support\EnvWriter;

function tempEnvFile(string $contents = ''): string
{
    $path = tempnam(sys_get_temp_dir(), 'env').'.env';

    file_put_contents($path, $contents);

    return $path;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'env*.env') ?: [] as $file) {
        @unlink($file);
    }
});

test('appends a missing key and verifies the write', function () {
    $path = tempEnvFile("APP_NAME=Desk\n");

    expect((new EnvWriter($path))->ensurePresent('LICENCE_SIGNING_KEY', str_repeat('b', 64)))->toBeTrue();
    expect((new EnvWriter($path))->readValue('LICENCE_SIGNING_KEY'))->toBe(str_repeat('b', 64));
    expect((new EnvWriter($path))->readValue('APP_NAME'))->toBe('Desk');
});

test('never overwrites a usable value', function () {
    $path = tempEnvFile('LICENCE_SIGNING_KEY='.str_repeat('a', 64)."\n");

    expect((new EnvWriter($path))->ensurePresent('LICENCE_SIGNING_KEY', str_repeat('b', 64)))->toBeFalse();
    expect((new EnvWriter($path))->readValue('LICENCE_SIGNING_KEY'))->toBe(str_repeat('a', 64));
});

test('fills a present-but-blank line instead of duplicating', function () {
    $path = tempEnvFile("LICENCE_SIGNING_KEY=\n");

    expect((new EnvWriter($path))->ensurePresent('LICENCE_SIGNING_KEY', str_repeat('c', 64)))->toBeTrue();
    expect(substr_count(file_get_contents($path), 'LICENCE_SIGNING_KEY='))->toBe(1);
});

test('creates the file when absent', function () {
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'env'.uniqid().'.env';

    expect((new EnvWriter($path))->ensurePresent('NEW_KEY', 'value'))->toBeTrue();
    expect((new EnvWriter($path))->readValue('NEW_KEY'))->toBe('value');

    @unlink($path);
});

test('quotes values with spaces and reads them back', function () {
    $path = tempEnvFile('');

    (new EnvWriter($path))->ensurePresent('GREETING', 'hello world');

    expect((new EnvWriter($path))->readValue('GREETING'))->toBe('hello world');
});

test('failure messages never contain the secret', function () {
    $path = tempEnvFile('');
    $writer = new class($path) extends EnvWriter
    {
        protected function lines(): array
        {
            return ['KEY=old'."\n"];
        }
    };

    try {
        $writer->ensurePresent('KEY', 's3cr3t-value');
        $this->fail('Expected a RuntimeException.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('s3cr3t-value');
    }
});
