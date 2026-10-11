<?php

declare(strict_types=1);

namespace B4x\Ksef\Doctor;

use B4x\Ksef\Auth\CertificateCredentials;
use B4x\Ksef\Auth\ContextIdentifier;
use B4x\Ksef\Auth\Credentials;
use B4x\Ksef\Auth\KsefTokenCredentials;
use B4x\Ksef\Environment;
use B4x\Ksef\Exception\KsefException;
use B4x\Ksef\KsefClient;
use B4x\Ksef\Support\SystemClock;
use B4x\Ksef\Testing\TestEnvironment;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

/**
 * The `ksef-doctor` command: reads the settings from environment variables, runs the {@see Doctor} and prints the report.
 *
 * Exit codes: 0 nothing failed (warnings are allowed), 1 a check failed, 2 the command line or the settings are wrong.
 *
 * @internal run `vendor/bin/ksef-doctor`
 */
final class Cli
{
    private const USAGE = <<<'TEXT'
        ksef-doctor: checks, read-only, that this installation is ready to work with KSeF.

        Usage:  vendor/bin/ksef-doctor --env=test|demo|production [--json] [--keep-session] [--no-color]

        Settings come from environment variables (never from the command line, which shell histories keep):
          KSEF_NIP                  the NIP of the taxpayer to work for
          KSEF_TOKEN                a KSeF token, or
          KSEF_CERT, KSEF_KEY       paths of a PEM certificate and its private key (KSEF_KEY_PASSPHRASE if encrypted), or
          KSEF_P12, KSEF_P12_PASSWORD   a PKCS#12 file and its password

        With --env=test and no credentials, a throw-away taxpayer is created on the TEST environment.
        The check logs in (and ends that login again unless --keep-session); it sends nothing else.

        TEXT;

    /**
     * @param list<string> $args the command line arguments without the script name
     * @param array<string, string> $env
     * @param callable(string): void $out
     * @param array{ClientInterface, RequestFactoryInterface, StreamFactoryInterface}|null $http the HTTP client and factories; found automatically (Guzzle) when null
     */
    public static function run(array $args, array $env, callable $out, ?array $http = null, ?ClockInterface $clock = null, ?string $baseUrl = null): int
    {
        $options = [];
        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                $out(self::USAGE);

                return 0;
            }
            if (preg_match('/^--(env|json|keep-session|no-color)(?:=(.*))?$/D', $arg, $m) !== 1) {
                $out("Unknown argument \"$arg\".\n\n" . self::USAGE);

                return 2;
            }
            $options[$m[1]] = $m[2] ?? '1';
        }

        $environment = Environment::tryFrom(match ($options['env'] ?? '') {
            'test' => Environment::Test->value,
            'demo' => Environment::Demo->value,
            'production' => Environment::Production->value,
            default => '',
        });
        if ($environment === null) {
            $out("Choose the environment explicitly: --env=test, --env=demo or --env=production.\n\n" . self::USAGE);

            return 2;
        }

        $http ??= self::findHttpClient();
        if ($http === null) {
            $out("No PSR-18 HTTP client found. Install one: composer require guzzlehttp/guzzle\n");

            return 2;
        }
        [$client, $requests, $streams] = $http;
        $clock ??= new SystemClock();

        try {
            [$context, $credentials, $certificatePem, $notes] = self::credentials($env, $environment, $client, $requests, $streams);
        } catch (KsefException|RuntimeException $e) {
            $out('Settings problem: ' . $e->getMessage() . "\n\n" . self::USAGE);

            return 2;
        }

        $builder = KsefClient::builder()->environment($environment)->httpClient($client, $requests, $streams)->context($context)->credentials($credentials)->clock($clock);
        if ($baseUrl !== null) {
            $builder->baseUrl($baseUrl);
        }
        $report = (new Doctor($builder->build(), $environment, $client, $requests, $clock, $certificatePem, !isset($options['keep-session']), $baseUrl))->run();

        $out(isset($options['json'])
            ? json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
            : self::render($report, $environment, $notes, !isset($options['no-color']) && self::supportsColor()));

        return $report->hasFailures() ? 1 : 0;
    }

    /**
     * @param list<string> $notes
     */
    private static function render(DoctorReport $report, Environment $environment, array $notes, bool $color): string
    {
        $paint = static fn(string $text, string $code): string => $color ? "\033[{$code}m{$text}\033[0m" : $text;
        $lines = ['ksef-doctor, environment: ' . $environment->name, ...$notes, ''];
        foreach ($report->results as $result) {
            $tag = match ($result->status) {
                CheckStatus::Ok => $paint(' ok ', '32'),
                CheckStatus::Warning => $paint('warn', '33'),
                CheckStatus::Failed => $paint('FAIL', '31'),
                CheckStatus::Skipped => $paint('skip', '90'),
            };
            $lines[] = \sprintf('[%s] %-13s %s', $tag, $result->name, $result->message);
            foreach ($result->details as $detail) {
                $lines[] = '       ' . str_repeat(' ', 14) . $detail;
            }
        }
        $lines[] = '';
        $lines[] = \sprintf('%d ok, %d warning(s), %d failed, %d skipped.', $report->count(CheckStatus::Ok), $report->count(CheckStatus::Warning), $report->count(CheckStatus::Failed), $report->count(CheckStatus::Skipped));

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{ContextIdentifier, Credentials, string|null, list<string>}
     */
    private static function credentials(array $env, Environment $environment, ClientInterface $client, RequestFactoryInterface $requests, StreamFactoryInterface $streams): array
    {
        $nip = $env['KSEF_NIP'] ?? '';
        $token = $env['KSEF_TOKEN'] ?? '';
        $cert = $env['KSEF_CERT'] ?? '';
        $p12 = $env['KSEF_P12'] ?? '';

        if ($nip === '' && $token === '' && $cert === '' && $p12 === '') {
            if (!$environment->isTest()) {
                throw new RuntimeException('KSEF_NIP and a token, a certificate or a PKCS#12 file are required.');
            }
            $taxpayer = TestEnvironment::createTaxpayer($client, $requests, $streams);

            return [ContextIdentifier::nip($taxpayer->nip->value), $taxpayer->credentials(), $taxpayer->certificatePem, ['no credentials given: using a throw-away taxpayer (NIP ' . $taxpayer->nip->value . ')']];
        }

        if ($nip === '') {
            throw new RuntimeException('KSEF_NIP is required.');
        }
        $context = ContextIdentifier::nip($nip);

        if ($token !== '') {
            return [$context, new KsefTokenCredentials($token), null, []];
        }
        if ($cert !== '') {
            $key = $env['KSEF_KEY'] ?? throw new RuntimeException('KSEF_KEY (the private key) is required with KSEF_CERT.');
            $passphrase = ($env['KSEF_KEY_PASSPHRASE'] ?? '') === '' ? null : $env['KSEF_KEY_PASSPHRASE'];

            return [$context, CertificateCredentials::fromPemFiles($cert, $key, $passphrase), self::read($cert), []];
        }
        if ($p12 !== '') {
            $password = $env['KSEF_P12_PASSWORD'] ?? '';
            $credentials = CertificateCredentials::fromPkcs12(self::read($p12), $password);
            $bundle = [];
            openssl_pkcs12_read(self::read($p12), $bundle, $password);
            $pem = \is_array($bundle) ? ($bundle['cert'] ?? null) : null;

            return [$context, $credentials, \is_string($pem) ? $pem : null, []];
        }

        throw new RuntimeException('Give a token (KSEF_TOKEN), a certificate (KSEF_CERT and KSEF_KEY) or a PKCS#12 file (KSEF_P12).');
    }

    private static function read(string $path): string
    {
        $content = is_file($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw new RuntimeException(\sprintf('Cannot read "%s".', $path));
        }

        return $content;
    }

    /**
     * @return array{ClientInterface, RequestFactoryInterface, StreamFactoryInterface}|null
     */
    private static function findHttpClient(): ?array
    {
        if (class_exists(\GuzzleHttp\Client::class) && class_exists(\GuzzleHttp\Psr7\HttpFactory::class)) {
            $factory = new \GuzzleHttp\Psr7\HttpFactory();

            return [new \GuzzleHttp\Client(['timeout' => 30, 'connect_timeout' => 10, 'http_errors' => false]), $factory, $factory];
        }
        return null;
    }

    private static function supportsColor(): bool
    {
        return \function_exists('posix_isatty') && @posix_isatty(\STDOUT) && getenv('NO_COLOR') === false;
    }
}
