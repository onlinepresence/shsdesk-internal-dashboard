<?php

namespace App\Support;

use RuntimeException;

/**
 * Append-only writer for the .env file. Keys holding a usable value
 * are never touched; missing keys are appended and then reread from
 * disk to verify the write. Values never appear in exceptions.
 * Framework-free by design so it stays unit-testable in isolation.
 */
class EnvWriter
{
    public function __construct(private string $path)
    {
        //
    }

    /**
     * Writer pointed at the application's loaded environment file.
     */
    public static function forApplication(): self
    {
        return new self(app()->environmentFilePath());
    }

    /**
     * Raw value of a key as written in the file, or null when the
     * key is absent or blank.
     */
    public function readValue(string $key): ?string
    {
        foreach ($this->lines() as $line) {
            $parsed = $this->parseLine($line);

            if ($parsed !== null && $parsed[0] === $key) {
                $value = trim($parsed[1], " \t\n\r\0\x0B\"'");

                return $value === '' ? null : $value;
            }
        }

        return null;
    }

    /**
     * Whether the file holds a usable value for the key. A value
     * injected by real server-side environment is invisible here by
     * design — callers check effective state via config() instead.
     */
    public function hasUsableValue(string $key): bool
    {
        return $this->readValue($key) !== null;
    }

    /**
     * Append a missing key, or fill a present-but-blank line. Returns
     * true only when a write happened and a fresh read confirms it.
     * Existing usable values are never overwritten.
     */
    public function ensurePresent(string $key, string $value): bool
    {
        if ($this->hasUsableValue($key)) {
            return false;
        }

        $lines = $this->lines();
        $assigned = $key.'='.$this->quote($value);
        $found = false;

        foreach ($lines as $index => $line) {
            $parsed = $this->parseLine($line);

            if ($parsed !== null && $parsed[0] === $key) {
                $lines[$index] = $assigned;
                $found = true;
            }
        }

        if (! $found) {
            if ($lines !== [] && ! str_ends_with(end($lines), "\n")) {
                $lines[count($lines) - 1] .= "\n";
            }

            $lines[] = $assigned."\n";
        }

        if (file_put_contents($this->path, implode('', $lines), LOCK_EX) === false) {
            throw new RuntimeException('Could not write the environment file.');
        }

        if ($this->readValue($key) !== $value) {
            throw new RuntimeException("Could not verify {$key} after writing the environment file.");
        }

        return true;
    }

    /**
     * Split a `KEY=value` line, tolerating an `export ` prefix.
     * Comments, blanks, and non-assignments return null.
     *
     * @return array{string, string}|null
     */
    protected function parseLine(string $line): ?array
    {
        if (! preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/', $line, $matches)) {
            return null;
        }

        return [$matches[1], $matches[2]];
    }

    /**
     * Quote values containing whitespace or shell-significant marks.
     */
    protected function quote(string $value): string
    {
        if (preg_match('/[\s#"\'`$\\\\]/', $value) === 1) {
            return '"'.addcslashes($value, '"\\$`').'"';
        }

        return $value;
    }

    /**
     * File lines with endings preserved, or an empty array when the
     * file does not exist yet.
     *
     * @return list<string>
     */
    protected function lines(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $lines = file($this->path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new RuntimeException('Could not read the environment file.');
        }

        return array_map(fn (string $line): string => $line."\n", $lines);
    }
}
