<?php

declare(strict_types=1);

namespace Ksef\Exception;

/**
 * The invoice exists but cannot be downloaded yet (HTTP 406).
 *
 * Observed on the KSeF environments: right after an invoice reaches status 200 and receives its
 * KSeF number, it is not yet permanently stored (`permanentStorageDate` is still empty) and the
 * download endpoint answers 406. The condition resolves on its own within seconds; use
 * `KsefClient::downloadInvoice($number, $waitPolicy)` to wait for it. This behaviour is not part
 * of the published OpenAPI contract.
 */
final class InvoiceNotAvailableException extends ApiException {}
