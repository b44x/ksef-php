<?php

declare(strict_types=1);

use Ksef\Polling\PollingPolicy;

require __DIR__ . '/bootstrap.php';

/**
 * After a SubmissionOutcomeUnknownException, find out whether KSeF received the invoice.
 *
 *   php recover_unknown_outcome.php <sessionReference> <invoiceHash>
 */
$sessionReference = $argv[1] ?? throw new InvalidArgumentException('Pass the session reference.');
$invoiceHash = $argv[2] ?? throw new InvalidArgumentException('Pass the invoice hash.');

$ksef = createClient();
$submission = $ksef->findSubmission($sessionReference, $invoiceHash);

if ($submission === null) {
    // KSeF has no record of it in that session. Re-send the identical document: if it did arrive
    // after all, KSeF answers status 440 (duplicate) with the original KSeF number.
    echo "Not received. It is safe to send the same document again.\n";
    exit(0);
}

$result = $ksef->waitForInvoice($submission, new PollingPolicy(timeoutSeconds: 120.0));
echo "Received as {$submission->invoiceReference}: status {$result->status->code} {$result->status->description}\n";
