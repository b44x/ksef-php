<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Guards the claim "every endpoint of the KSeF OpenAPI specification is covered": each operation in the committed
 * snapshot must be reachable from a path literal in the sources. When KSeF adds an operation (see tools/spec.php)
 * and the snapshot is refreshed, this test fails until the SDK follows.
 */
final class SpecCoverageTest extends TestCase
{
    public function testEveryOperationOfTheSpecificationHasAnImplementation(): void
    {
        $snapshot = json_decode((string) file_get_contents(__DIR__ . '/../../resources/spec/ksef-api-snapshot.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($snapshot);
        self::assertIsArray($snapshot['operations']);

        $sources = '';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../src', FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $sources .= (string) file_get_contents($file->getPathname());
            }
        }

        $missing = [];
        foreach (array_keys($snapshot['operations']) as $operation) {
            [$method, $path] = explode(' ', (string) $operation, 2);
            $segments = preg_split('/\{[^}]+\}/', $path);
            self::assertIsArray($segments);
            $first = $segments[0];
            $last = $segments[\count($segments) - 1];
            // The prefix before the first placeholder and the suffix after the last one must both appear as literals.
            $prefix = $first !== '' ? rtrim($first, '/') : '';
            $suffix = \count($segments) > 1 ? $last : '';
            $found = ($prefix === '' || str_contains($sources, $prefix)) && ($suffix === '' || str_contains($sources, $suffix));
            if (!$found) {
                $missing[] = $operation;
            }
        }

        self::assertSame([], $missing, 'Operations of the specification without an implementation in src/.');
    }
}
