<?php

declare(strict_types=1);

namespace Ksef\Exception;

/** KSeF answered with a successful status, but the body does not match the documented contract. */
final class MalformedResponseException extends TransportException {}
