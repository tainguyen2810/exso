<?php

namespace App\Installer;

use RuntimeException;

/**
 * Updates KEY=value lines in the .env file, adding missing keys at the end.
 */
class EnvWriter
{
    public function __construct(protected string $path) {}

    /**
     * @param  array<string, string|int|bool|null>  $values
     */
    public function set(array $values): void
    {
        $contents = file_exists($this->path) ? (string) file_get_contents($this->path) : '';

        foreach ($values as $key => $value) {
            $line = $key.'='.$this->format($value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            $contents = preg_match($pattern, $contents)
                ? (string) preg_replace_callback($pattern, fn (): string => $line, $contents, 1)
                : rtrim($contents, "\n")."\n".$line."\n";
        }

        if (file_put_contents($this->path, $contents) === false) {
            throw new RuntimeException("Không ghi được file {$this->path}.");
        }
    }

    protected function format(string|int|bool|null $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $value = (string) $value;

        if ($value === '' || preg_match('/[\s#"\'\\\\$]/', $value)) {
            return '"'.addcslashes($value, '"\\$').'"';
        }

        return $value;
    }
}
