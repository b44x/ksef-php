<?php

declare(strict_types=1);

namespace Ksef\Exception;

/**
 * Polling stopped because the configured timeout or attempt limit was reached.
 *
 * The remote operation may still complete later; keep the references and poll again.
 */
final class PollingTimeoutException extends KsefException {}
