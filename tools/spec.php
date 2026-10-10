#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Detects drift between the KSeF OpenAPI specification and the snapshot this SDK was built against.
 *
 *   php tools/spec.php snapshot [openapi.json|URL]   write a new snapshot to stdout
 *   php tools/spec.php check [URL]                    compare the live specification with resources/spec/ksef-api-snapshot.json
 *
 * `check` exits with 1 when operations or request constraints were added, removed or changed, and prints what moved.
 * Run it on a schedule (see .github/workflows/spec-drift.yml); after reviewing a change, refresh the snapshot with
 * `snapshot` and commit it together with the code that follows the change.
 */

const DEFAULT_URL = 'https://api.ksef.mf.gov.pl/docs/v2/openapi.json';
const SNAPSHOT = __DIR__ . '/../resources/spec/ksef-api-snapshot.json';
const KEYS = ['minLength', 'maxLength', 'minimum', 'maximum', 'pattern', 'maxItems', 'minItems', 'enum', 'required'];

/**
 * @return array<string, mixed>
 */
function load(string $source): array
{
    $json = str_starts_with($source, 'http') ? file_get_contents($source) : file_get_contents($source);
    if ($json === false) {
        fwrite(STDERR, "Cannot read $source\n");
        exit(2);
    }

    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @param array<string, mixed> $schema
 * @param array<string, mixed> $spec
 *
 * @return array<string, mixed>
 */
function resolve(array $schema, array $spec): array
{
    $out = [];
    if (isset($schema['$ref'])) {
        $name = substr((string) $schema['$ref'], strrpos((string) $schema['$ref'], '/') + 1);
        $out = resolve($spec['components']['schemas'][$name] ?? [], $spec);
    }
    foreach ($schema['allOf'] ?? [] as $part) {
        $out = array_replace($out, resolve($part, $spec));
    }
    foreach ($schema as $key => $value) {
        if ($key !== 'allOf' && $key !== '$ref') {
            $out[$key] = $value;
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $schema
 * @param array<string, mixed> $spec
 * @param array<string, array<string, mixed>> $out
 * @param array<string, true> $seen
 */
function flatten(array $schema, string $path, array $spec, array &$out, int $depth = 0): void
{
    if ($depth > 12) {
        return;
    }
    $resolved = resolve($schema, $spec);
    $constraints = array_intersect_key($resolved, array_flip(KEYS));
    if (isset($constraints['enum'])) {
        sort($constraints['enum']);
    }
    if (isset($constraints['required'])) {
        sort($constraints['required']);
    }
    if ($constraints !== []) {
        $out[$path] = $constraints;
    }
    if (($resolved['type'] ?? null) === 'array' && isset($resolved['items'])) {
        flatten($resolved['items'], $path . '[]', $spec, $out, $depth + 1);
    }
    foreach ($resolved['properties'] ?? [] as $name => $property) {
        flatten($property, $path . '.' . $name, $spec, $out, $depth + 1);
    }
}

/**
 * @param array<string, mixed> $spec
 *
 * @return array<string, mixed>
 */
function snapshot(array $spec): array
{
    preg_match('/Wersja API:\*\*\s*([0-9.]+)/', (string) ($spec['info']['description'] ?? ''), $m);
    $operations = [];
    foreach ($spec['paths'] as $path => $methods) {
        if (str_starts_with($path, '/testdata')) {
            continue;
        }
        foreach ($methods as $method => $operation) {
            if (!in_array($method, ['get', 'post', 'put', 'delete', 'patch'], true)) {
                continue;
            }
            $entry = ['responses' => array_map('strval', array_keys($operation['responses'] ?? []))];
            sort($entry['responses']);
            $params = [];
            foreach ($operation['parameters'] ?? [] as $parameter) {
                $parameter = resolve($parameter, $spec);
                $schema = resolve($parameter['schema'] ?? [], $spec);
                $params[$parameter['in'] . ':' . $parameter['name']] = array_intersect_key($schema, array_flip(KEYS)) + ['required' => (bool) ($parameter['required'] ?? false)];
            }
            ksort($params);
            if ($params !== []) {
                $entry['parameters'] = $params;
            }
            $body = $operation['requestBody']['content']['application/json']['schema'] ?? null;
            if ($body !== null) {
                $fields = [];
                flatten($body, 'body', $spec, $fields);
                ksort($fields);
                $entry['body'] = $fields;
            }
            $operations[strtoupper($method) . ' ' . $path] = $entry;
        }
    }
    ksort($operations);

    return ['apiVersion' => $m[1] ?? 'unknown', 'operations' => $operations];
}

/**
 * @param array<string, mixed> $old
 * @param array<string, mixed> $new
 *
 * @return list<string>
 */
function diff(array $old, array $new): array
{
    $report = [];
    if (($old['apiVersion'] ?? null) !== ($new['apiVersion'] ?? null)) {
        $report[] = sprintf('API version: %s -> %s', $old['apiVersion'] ?? '?', $new['apiVersion'] ?? '?');
    }
    foreach (array_diff_key($new['operations'], $old['operations']) as $name => $_) {
        $report[] = "NEW operation: $name";
    }
    foreach (array_diff_key($old['operations'], $new['operations']) as $name => $_) {
        $report[] = "REMOVED operation: $name";
    }
    foreach (array_intersect_key($new['operations'], $old['operations']) as $name => $operation) {
        $before = $old['operations'][$name];
        if (($before['responses'] ?? []) !== $operation['responses']) {
            $report[] = "$name: response codes " . implode(',', $before['responses'] ?? []) . ' -> ' . implode(',', $operation['responses']);
        }
        foreach (['parameters', 'body'] as $part) {
            $a = $before[$part] ?? [];
            $b = $operation[$part] ?? [];
            foreach (array_diff_key($b, $a) as $field => $constraints) {
                $report[] = "$name: new $part field $field " . json_encode($constraints, JSON_UNESCAPED_UNICODE);
            }
            foreach (array_diff_key($a, $b) as $field => $_) {
                $report[] = "$name: $part field $field lost its constraints or was removed";
            }
            foreach (array_intersect_key($b, $a) as $field => $constraints) {
                if ($a[$field] !== $constraints) {
                    $report[] = "$name: $part $field changed " . json_encode($a[$field], JSON_UNESCAPED_UNICODE) . ' -> ' . json_encode($constraints, JSON_UNESCAPED_UNICODE);
                }
            }
        }
    }

    return $report;
}

$command = $argv[1] ?? '';
if ($command === 'snapshot') {
    echo json_encode(snapshot(load($argv[2] ?? DEFAULT_URL)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}
if ($command === 'check') {
    $old = json_decode((string) file_get_contents(SNAPSHOT), true, 512, JSON_THROW_ON_ERROR);
    $changes = diff($old, snapshot(load($argv[2] ?? DEFAULT_URL)));
    if ($changes === []) {
        echo "No drift: the KSeF specification matches the snapshot (API version {$old['apiVersion']}).\n";
        exit(0);
    }
    echo "The KSeF specification differs from the snapshot:\n - ", implode("\n - ", $changes), "\n";
    echo "\nReview the changes, update the SDK where needed, then refresh the snapshot:\n  php tools/spec.php snapshot > resources/spec/ksef-api-snapshot.json\n";
    exit(1);
}
fwrite(STDERR, "Usage: php tools/spec.php snapshot [openapi.json|URL]  |  php tools/spec.php check [URL]\n");
exit(2);
