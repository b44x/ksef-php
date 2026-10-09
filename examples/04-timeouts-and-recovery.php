<?php

declare(strict_types=1);

/**
 * 04 - What happens when the network fails in the middle of sending an invoice.
 *
 *   php examples/04-timeouts-and-recovery.php
 *
 * The dangerous moment: the request left your server, KSeF may have stored the invoice, but you never saw the
 * answer. Sending blindly again could create a duplicate, giving up could lose an invoice. The SDK handles it:
 *   1. look the document up in the session by its hash - if KSeF has it, you are done;
 *   2. otherwise re-send the identical document (KSeF rejects true duplicates with status 440);
 *   3. only if that stays inconclusive, you get SubmissionOutcomeUnknownException to decide.
 *
 * This example makes the network fail on purpose (a decorator that "loses" the first invoice upload response).
 */

use B4x\Ksef\Environment;
use B4x\Ksef\Exception\SubmissionOutcomeUnknownException;
use B4x\Ksef\Invoice\InvoiceLine;
use B4x\Ksef\Invoice\VatRate;
use B4x\Ksef\KsefClient;
use B4x\Ksef\Session\SubmissionRecoveryPolicy;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

require __DIR__ . '/bootstrap.php';

/** Delivers the invoice upload to KSeF but throws away the response, like a connection reset would. */
final class LoseOneResponse implements ClientInterface
{
    public int $lost = 0;

    public function __construct(private readonly ClientInterface $inner) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->inner->sendRequest($request);
        if ($this->lost === 0 && $request->getMethod() === 'POST' && str_ends_with($request->getUri()->getPath(), '/invoices')) {
            ++$this->lost;

            throw new class ('connection reset by peer', $request) extends RuntimeException implements NetworkExceptionInterface {
                public function __construct(string $message, private readonly RequestInterface $request)
                {
                    parent::__construct($message);
                }

                public function getRequest(): RequestInterface
                {
                    return $this->request;
                }
            };
        }

        return $response;
    }
}

$example = example();
if ($example->credentials === null) {
    exit("This demonstration needs the zero-config TEST mode (run it without KSEF_* variables).\n");
}

$flaky = new LoseOneResponse($example->http);
$client = KsefClient::builder()
    ->environment(Environment::Test)
    ->httpClient($flaky, $example->factory, $example->factory)
    ->context(B4x\Ksef\Auth\ContextIdentifier::nip($example->nip->value))
    ->credentials($example->credentials)
    // The default; shown for clarity. Use SubmissionRecoveryPolicy::disabled() to handle everything yourself.
    ->submissionRecovery(new SubmissionRecoveryPolicy(maxResends: 2))
    ->build();

step('Sending while the first response gets lost');
try {
    $submission = $client->sendInvoice($example->invoice()->addLine(InvoiceLine::of('Consulting', '1', 'h', '100.00', VatRate::Rate23))->build());
    say(sprintf('  Responses lost: %d. The SDK found the invoice in KSeF instead of sending a second copy.', $flaky->lost));
    say('  recovered flag: ' . ($submission->recovered ? 'true' : 'false'));

    $result = $client->waitForInvoice($submission)->assertStored();   // assertStored(): accepted OR already stored (duplicate)
    say('  KSeF number: ' . $result->resolvedKsefNumber());
} catch (SubmissionOutcomeUnknownException $e) {
    // Reached only when even the lookup and the re-sends were inconclusive. Persist these and decide later.
    say(sprintf('  Still unknown. session=%s hash=%s', $e->sessionReference, $e->invoiceHash));
    $found = $client->findSubmission($e->sessionReference, $e->invoiceHash);   // can be repeated any time
    say($found === null ? '  KSeF has no record of it: safe to send again.' : '  KSeF has it: ' . $found->invoiceReference);
}
