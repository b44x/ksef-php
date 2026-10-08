<?php

declare(strict_types=1);

namespace Ksef\Exception;

/** An XML document could not be built, parsed or validated against its XSD schema. */
final class SerializationException extends ValidationException {}
