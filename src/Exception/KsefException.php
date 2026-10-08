<?php

declare(strict_types=1);

namespace Ksef\Exception;

use RuntimeException;

/**
 * Base class of every exception thrown by this library.
 *
 * Catch this type to handle all library failures; catch the subclasses to react to specific ones.
 */
class KsefException extends RuntimeException {}
