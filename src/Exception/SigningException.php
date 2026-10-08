<?php

declare(strict_types=1);

namespace Ksef\Exception;

/** A document could not be signed (invalid key or certificate, unsupported algorithm, ...). */
final class SigningException extends KsefException {}
