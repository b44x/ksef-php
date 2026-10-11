<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Integration;

use B4x\Ksef\Doctor\CheckStatus;
use B4x\Ksef\Doctor\Cli;
use B4x\Ksef\Tests\Support\Http;
use Psr\Http\Message\ResponseInterface;

final class DoctorTest extends KsefTestCase
{
    private const SESSION = ['maxInvoices' => 10000, 'maxInvoiceSizeInMB' => 1, 'maxInvoiceWithAttachmentSizeInMB' => 3];

    private string $output = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ksef->json('GET', '/limits/context', 200, ['onlineSession' => self::SESSION, 'batchSession' => self::SESSION, 'collectiveIdentifier' => ['maxInvoices' => 5000]]);
        $this->ksef->json('GET', '/limits/subject', 200, ['enrollment' => ['maxEnrollments' => 300], 'certificate' => ['maxCertificates' => 100]]);
        $this->ksef->json('GET', '/rate-limits', 200, ['invoiceSend' => ['perSecond' => 10, 'perMinute' => 30, 'perHour' => 180]]);
        $this->ksef->json('POST', '/permissions/query/personal/grants', 200, ['hasMore' => false, 'permissions' => []]);
        $this->ksef->json('GET', '/permissions/attachments/status', 200, ['isAttachmentAllowed' => false]);
        $this->ksef->json('POST', '/invoices/query/metadata', 200, ['hasMore' => false, 'isTruncated' => false, 'invoices' => []]);
        $this->ksef->json('DELETE', '/auth/sessions/current', 204, []);
        $this->ksef->on('GET', '/status', static fn(): ResponseInterface => Http::json(200, ['status' => 'AVAILABLE']));
    }

    public function testAHealthyInstallationPasses(): void
    {
        $code = $this->doctor(['--env=test', '--no-color']);

        self::assertSame(0, $code, $this->output);
        self::assertStringContainsString('[ ok ] Login', $this->output);
        self::assertStringContainsString('invoice sending: 10/s, 30/min, 180/h', $this->output);
        self::assertStringContainsString('the login session created for this check was ended', $this->output);
        self::assertCount(1, $this->ksef->requestsTo('DELETE', '/auth/sessions/current'));
        self::assertSame([], $this->ksef->requestsTo('POST', '/sessions/online'), 'The doctor opens no session and sends nothing.');
    }

    public function testAFailedLoginIsExplainedAndTheRestIsSkipped(): void
    {
        $this->ksef->routesReset('POST', '/auth/ksef-token')->json('POST', '/auth/ksef-token', 401, ['title' => 'Unauthorized', 'status' => 401, 'detail' => 'Token invalid', 'traceId' => 'trace-9']);

        $code = $this->doctor(['--env=test', '--no-color']);

        self::assertSame(1, $code);
        self::assertStringContainsString('[FAIL] Login', $this->output);
        self::assertStringContainsString('the credentials were refused', $this->output);
        self::assertStringContainsString('trace id trace-9', $this->output);
        self::assertStringContainsString('[skip] Limits', $this->output);
    }

    public function testKsefReportingAFailureIsAWarningNotAFailure(): void
    {
        $this->ksef->routesReset('GET', '/status')->on('GET', '/status', static fn(): ResponseInterface => Http::json(200, ['status' => 'FAILURE', 'messages' => []]));

        $code = $this->doctor(['--env=test', '--no-color']);

        self::assertSame(0, $code, $this->output);
        self::assertStringContainsString('[warn] Availability', $this->output);
    }

    public function testTheJsonOutputIsMachineReadable(): void
    {
        $code = $this->doctor(['--env=test', '--json']);

        $data = json_decode($this->output, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(0, $code);
        self::assertIsArray($data);
        self::assertTrue($data['ok']);
        self::assertIsArray($data['results']);
        self::assertContains(CheckStatus::Ok->value, array_column($data['results'], 'status'));
    }

    public function testBadUsageAndMissingSettingsExitWithCodeTwo(): void
    {
        self::assertSame(2, $this->doctor([]), 'The environment must be chosen explicitly.');
        self::assertStringContainsString('Choose the environment explicitly', $this->output);

        self::assertSame(2, $this->doctor(['--env=production'], []), 'Production needs real credentials.');
        self::assertStringContainsString('KSEF_NIP', $this->output);

        self::assertSame(2, $this->doctor(['--env=test', '--wat'], ['KSEF_NIP' => '5265877635', 'KSEF_TOKEN' => 't']));
        self::assertSame(0, $this->doctor(['--help']));
        self::assertStringContainsString('Usage:', $this->output);
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $env
     */
    private function doctor(array $args, array $env = ['KSEF_NIP' => '5265877635', 'KSEF_TOKEN' => 'secret-ksef-token']): int
    {
        $this->output = '';
        $factory = Http::factory();

        return Cli::run($args, $env, function (string $text): void {
            $this->output .= $text;
        }, [$this->ksef->http, $factory, $factory], $this->clock, 'https://api.example.test/v2');
    }
}
