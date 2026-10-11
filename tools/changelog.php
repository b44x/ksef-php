#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Prints the CHANGELOG.md section of a version, for the release notes.
 *
 *   php tools/changelog.php 0.12.0        the notes of 0.12.0 (a leading "v" is accepted)
 *   php tools/changelog.php --prerelease 0.12.0   prints "true" for 0.x versions (the API is not stable yet), "false" from 1.0 on
 *
 * Exits with 1 when the version has no section or the section is empty, so a release cannot go out without notes.
 */

/**
 * @return string|null the body of the `## [version]` section without its heading, or null when there is none
 */
function changelogSection(string $changelog, string $version): ?string
{
    $body = [];
    $found = false;
    foreach (preg_split('/\R/', $changelog) ?: [] as $line) {
        if (preg_match('/^## \[([^\]]+)\]/', $line, $m) === 1) {
            if ($found) {
                break;
            }
            $found = $m[1] === $version;

            continue;
        }
        if ($found && preg_match('/^\[[^\]]+\]: /', $line) === 1) {
            break; // the link references at the end of the file
        }
        if ($found) {
            $body[] = $line;
        }
    }

    $text = trim(implode("\n", $body));

    return $text === '' ? null : $text;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $args = array_slice($argv, 1);
    $prerelease = in_array('--prerelease', $args, true);
    $version = ltrim((string) (array_values(array_diff($args, ['--prerelease']))[0] ?? ''), 'v');
    if (preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1) {
        fwrite(STDERR, "Usage: php tools/changelog.php [--prerelease] <version, for example 0.12.0>\n");
        exit(2);
    }
    if ($prerelease) {
        echo $version[0] === '0' ? "true\n" : "false\n";
        exit(0);
    }

    $section = changelogSection((string) file_get_contents(__DIR__ . '/../CHANGELOG.md'), $version);
    if ($section === null) {
        fwrite(STDERR, "CHANGELOG.md has no notes for $version: add a section \"## [$version] - date\" before tagging.\n");
        exit(1);
    }
    echo $section . "\n";
}
