<?php

declare(strict_types=1);

namespace B4x\Ksef\Exception;

/**
 * The client uses manual signing (`ManualCredentials`) and has no valid tokens: the person has to sign an
 * authentication request first (`createAuthenticationRequest()`, then `authenticateWithSignedRequest()`).
 */
final class ManualSignatureRequiredException extends AuthenticationException {}
