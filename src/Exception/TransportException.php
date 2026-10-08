<?php

declare(strict_types=1);

namespace Ksef\Exception;

/**
 * The HTTP exchange failed below the API level (connection error, timeout, unusable response).
 *
 * A TransportException never proves that KSeF did not process the request. See
 * {@see SubmissionOutcomeUnknownException} for the invoice submission case.
 */
class TransportException extends KsefException {}
