<?php

declare(strict_types=1);

/**
 * Loads a .env file into process environment variables.
 */
function loadDotEnv(string $envFilePath): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    if (!is_file($envFilePath)) {
        $loaded = true;
        return;
    }

    $lines = file($envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException('No se pudo leer el archivo .env');
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        $isDoubleQuoted = str_starts_with($value, '"') && str_ends_with($value, '"');
        $isSingleQuoted = str_starts_with($value, "'") && str_ends_with($value, "'");
        if ($isDoubleQuoted || $isSingleQuoted) {
            $value = substr($value, 1, -1);
        }

        if ($name === '') {
            continue;
        }

        if (getenv($name) === false) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    $loaded = true;
}

/**
 * Reads a required environment variable.
 */
function envOrFail(string $name): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException('Falta variable de entorno requerida: ' . $name);
    }

    return $value;
}

/**
 * Reads an optional integer environment variable.
 */
function envInt(string $name, int $default): int
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        return $default;
    }

    return (int)$value;
}
