<?php

declare(strict_types=1);

namespace B4x\Ksef\Doctor;

use B4x\Ksef\Environment;
use B4x\Ksef\Exception\ApiException;
use B4x\Ksef\Exception\KsefException;
use B4x\Ksef\KsefClient;
use B4x\Ksef\Latarnia\AvailabilityStatus;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Throwable;

/**
 * Checks, without changing anything in KSeF, that an installation is ready: PHP, the certificate, the clock, KSeF's
 * availability, the login, the limits, the permissions and read access to invoices.
 *
 * The login is the only thing that leaves a trace (a session in KSeF's list of logins); the doctor ends it again unless
 * told to keep it. Nothing is sent: no invoice, no session, no permission change.
 */
final class Doctor
{
    private const MAX_CLOCK_SKEW_SECONDS = 60;
    private const CERTIFICATE_WARNING_DAYS = 30;

    /**
     * @param string|null $certificatePem the signing certificate, when logging in with one (for the expiry checks)
     * @param string|null $baseUrl the API base URL when it differs from the environment's
     */
    public function __construct(
        private readonly KsefClient $ksef,
        private readonly Environment $environment,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly ClockInterface $clock,
        private readonly ?string $certificatePem = null,
        private readonly bool $revokeSession = true,
        private readonly ?string $baseUrl = null,
    ) {}

    public function run(): DoctorReport
    {
        $results = [$this->php(), $this->certificate(), $this->apiAndClock(), $this->availability()];

        $login = $this->step('Login', function (): array {
            $started = microtime(true);
            $limits = $this->ksef->contextLimits();

            return [\sprintf('logged in and authorised (%d ms)', (int) round((microtime(true) - $started) * 1000)), [], $limits];
        });
        $results[] = $login['result'];

        if ($login['result']->status === CheckStatus::Failed) {
            foreach (['Limits', 'Permissions', 'Invoices'] as $name) {
                $results[] = new CheckResult($name, CheckStatus::Skipped, 'needs a working login');
            }

            return new DoctorReport($results);
        }

        $results[] = $this->step('Limits', function (): array {
            $context = $this->ksef->contextLimits();
            $subject = $this->ksef->subjectLimits();
            $rates = $this->ksef->rateLimits();
            $send = $rates['invoiceSend'] ?? null;

            return [
                \sprintf('online session up to %d invoices, batch up to %d', $context->onlineSession->maxInvoices, $context->batchSession->maxInvoices),
                array_values(array_filter([
                    $send === null ? null : \sprintf('invoice sending: %d/s, %d/min, %d/h', $send->perSecond, $send->perMinute, $send->perHour),
                    \sprintf('certificates: up to %s, enrolments: up to %s', $subject->maxCertificates ?? 'n/a', $subject->maxEnrollments ?? 'n/a'),
                ])),
            ];
        })['result'];

        $results[] = $this->step('Permissions', function (): array {
            $held = [];
            foreach ($this->ksef->listMyPermissions()->items as $permission) {
                $held[$permission->scope] = true;
            }
            $scopes = array_keys($held);
            $consent = $this->ksef->attachmentStatus();

            $details = ['attachments in invoices: ' . ($consent->allowed ? 'allowed' : 'not allowed (the taxpayer has not given consent)')];
            if ($scopes === []) {
                // The owner of a sole proprietorship works with full rights that are not listed as grants.
                return ['no explicit permissions listed (the owner of the NIP has full rights without grants)', $details];
            }
            if (!isset($held['InvoiceWrite'])) {
                return ['holds: ' . implode(', ', $scopes), [...$details, 'this login has no permission to issue invoices (InvoiceWrite)'], null, CheckStatus::Warning];
            }

            return ['holds: ' . implode(', ', $scopes), $details];
        })['result'];

        $results[] = $this->step('Invoices', function (): array {
            $page = $this->ksef->searchInvoices(
                \B4x\Ksef\Api\InvoiceSubjectType::Seller,
                \B4x\Ksef\Api\InvoiceDateType::Issue,
                $this->clock->now()->modify('-7 days'),
                $this->clock->now(),
                pageSize: 10,
            );

            return [\sprintf('can search invoices (%d sold in the last 7 days%s)', \count($page->invoices), $page->hasMore ? ', and more' : ''), []];
        })['result'];

        if ($this->revokeSession) {
            $results[] = $this->step('Cleanup', function (): array {
                $this->ksef->revokeAuthSession();

                return ['the login session created for this check was ended', []];
            })['result'];
        }

        return new DoctorReport($results);
    }

    private function php(): CheckResult
    {
        $problems = [];
        if (version_compare(PHP_VERSION, '8.2.0', '<')) {
            $problems[] = 'PHP 8.2 or newer is required, this is ' . PHP_VERSION;
        }
        foreach (['dom', 'json', 'libxml', 'mbstring', 'openssl'] as $extension) {
            if (!\extension_loaded($extension)) {
                $problems[] = 'the "' . $extension . '" extension is missing';
            }
        }
        if ($problems !== []) {
            return new CheckResult('PHP', CheckStatus::Failed, implode('; ', $problems));
        }
        if (!\extension_loaded('zip')) {
            return new CheckResult('PHP', CheckStatus::Warning, 'PHP ' . PHP_VERSION . ', but ext-zip is missing', ['batch sessions (sendBatch) need ext-zip']);
        }

        return new CheckResult('PHP', CheckStatus::Ok, 'PHP ' . PHP_VERSION . ' with the required extensions');
    }

    private function certificate(): CheckResult
    {
        if ($this->certificatePem === null) {
            return new CheckResult('Certificate', CheckStatus::Skipped, 'not logging in with a certificate');
        }
        $info = openssl_x509_parse($this->certificatePem);
        if ($info === false) {
            return new CheckResult('Certificate', CheckStatus::Failed, 'the certificate cannot be read');
        }

        $now = $this->clock->now()->getTimestamp();
        $validTo = \is_int($info['validTo_time_t'] ?? null) ? $info['validTo_time_t'] : 0;
        $validFrom = \is_int($info['validFrom_time_t'] ?? null) ? $info['validFrom_time_t'] : 0;
        $subject = $this->subjectName($info['subject'] ?? null);
        $details = ['subject: ' . $subject, 'valid until ' . gmdate('Y-m-d', $validTo)];

        if ($validTo <= $now) {
            return new CheckResult('Certificate', CheckStatus::Failed, 'the certificate has expired', $details);
        }
        if ($validFrom > $now) {
            return new CheckResult('Certificate', CheckStatus::Failed, 'the certificate is not valid yet (check the clock)', $details);
        }
        if ($info['subject'] === $info['issuer'] && !$this->environment->isTest()) {
            return new CheckResult('Certificate', CheckStatus::Warning, 'the certificate is self-signed', [...$details, 'KSeF accepts self-signed certificates on the TEST environment only']);
        }
        $days = intdiv($validTo - $now, 86400);
        if ($days < self::CERTIFICATE_WARNING_DAYS) {
            return new CheckResult('Certificate', CheckStatus::Warning, \sprintf('the certificate expires in %d days', $days), $details);
        }

        return new CheckResult('Certificate', CheckStatus::Ok, \sprintf('valid for another %d days', $days), $details);
    }

    /** Reaches the API without logging in and compares the server's clock with ours. */
    private function apiAndClock(): CheckResult
    {
        $url = rtrim($this->baseUrl ?? $this->environment->baseUrl(), '/') . '/security/public-key-certificates';
        try {
            $response = $this->http->sendRequest($this->requests->createRequest('GET', $url)->withHeader('Accept', 'application/json'));
        } catch (Throwable $e) {
            return new CheckResult('API', CheckStatus::Failed, 'KSeF cannot be reached: ' . $e::class, ['check the network, proxy and firewall (outbound HTTPS to the KSeF host)']);
        }
        if ($response->getStatusCode() >= 400) {
            return new CheckResult('API', CheckStatus::Failed, 'KSeF answered HTTP ' . $response->getStatusCode(), ['check whether KSeF is available: see the availability check']);
        }

        $header = $response->getHeaderLine('Date');
        $server = $header === '' ? false : strtotime($header);
        if ($server === false) {
            return new CheckResult('API', CheckStatus::Ok, 'reachable (no Date header to compare the clock with)');
        }
        $skew = $this->clock->now()->getTimestamp() - $server;
        if (abs($skew) > self::MAX_CLOCK_SKEW_SECONDS) {
            return new CheckResult('API', CheckStatus::Warning, \sprintf('reachable, but this machine\'s clock is %d seconds %s', abs($skew), $skew > 0 ? 'ahead' : 'behind'), ['signatures and challenges depend on the time: synchronise the clock (NTP)']);
        }

        return new CheckResult('API', CheckStatus::Ok, \sprintf('reachable, clock within %d s of KSeF', abs($skew)));
    }

    private function availability(): CheckResult
    {
        if ($this->environment->latarniaUrl() === null) {
            return new CheckResult('Availability', CheckStatus::Skipped, 'no availability service for this environment');
        }

        $step = $this->step('Availability', function (): array {
            $report = $this->ksef->availability();
            $details = array_map(static fn($m): string => $m->title . ': ' . $m->text, $report->messages);

            // Only a warning: whether the API works is for the login to show. On TEST the service carries sample announcements.
            return [match ($report->status) {
                AvailabilityStatus::Available => 'KSeF reports that it is available',
                AvailabilityStatus::Maintenance => 'KSeF reports planned maintenance',
                AvailabilityStatus::Failure => 'KSeF reports a failure',
                AvailabilityStatus::TotalFailure => 'KSeF reports a total failure',
            }, $report->isAvailable() ? $details : [...$details, $this->environment->isTest() ? 'the TEST environment publishes sample announcements; do not read much into this here' : 'during a failure issue invoices offline (docs/OFFLINE.md)'], $report, $report->isAvailable() ? CheckStatus::Ok : CheckStatus::Warning];
        });

        return $step['result'];
    }

    /**
     * Runs a check, turning exceptions into a failed result that says what KSeF answered.
     *
     * @param callable(): array{0: string, 1: list<string>, 2?: mixed, 3?: CheckStatus} $check
     *
     * @return array{result: CheckResult, value: mixed}
     */
    private function step(string $name, callable $check): array
    {
        try {
            $outcome = $check();

            return ['result' => new CheckResult($name, $outcome[3] ?? CheckStatus::Ok, $outcome[0], $outcome[1]), 'value' => $outcome[2] ?? null];
        } catch (ApiException $e) {
            $details = array_values(array_filter([
                $e->ksefCode() === null ? null : 'KSeF code ' . $e->ksefCode(),
                $e->traceId === null ? null : 'trace id ' . $e->traceId . ' (give it to KSeF support)',
                ...array_map(static fn($error): string => $error->description, $e->errors),
                $this->hint($e),
            ], static fn(?string $line): bool => $line !== null));

            return ['result' => new CheckResult($name, CheckStatus::Failed, \sprintf('HTTP %d: %s', $e->httpStatus, $e->getMessage()), $details), 'value' => null];
        } catch (KsefException $e) {
            return ['result' => new CheckResult($name, CheckStatus::Failed, $e->getMessage()), 'value' => null];
        } catch (Throwable $e) {
            return ['result' => new CheckResult($name, CheckStatus::Failed, $e::class . ': ' . $e->getMessage()), 'value' => null];
        }
    }

    private function hint(ApiException $e): ?string
    {
        return match ($e->httpStatus) {
            401 => 'the credentials were refused: check the NIP, the token or the certificate, and that they belong to this environment',
            403 => 'authenticated, but not authorised: the subject lacks the permission for this operation in this context',
            429 => 'KSeF is rate limiting: wait and retry',
            default => null,
        };
    }

    private function subjectName(mixed $subject): string
    {
        if (!\is_array($subject)) {
            return 'unknown';
        }
        $parts = [];
        foreach (['CN', 'O', 'organizationIdentifier', 'serialNumber'] as $key) {
            if (isset($subject[$key]) && \is_string($subject[$key])) {
                $parts[] = $key . '=' . $subject[$key];
            }
        }

        return $parts === [] ? 'unknown' : implode(', ', $parts);
    }
}
