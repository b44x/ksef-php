<?php

declare(strict_types=1);

namespace Ksef\Exception;

/** The client was configured incorrectly (missing HTTP client, unreadable certificate, ...). */
final class ConfigurationException extends KsefException {}
