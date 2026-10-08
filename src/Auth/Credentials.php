<?php

declare(strict_types=1);

namespace Ksef\Auth;

/**
 * How the caller proves its identity to KSeF.
 *
 * The two supported mechanisms are {@see KsefTokenCredentials} and {@see CertificateCredentials}.
 * The interface is a marker used by the {@see Authenticator}; it intentionally exposes no secrets.
 */
interface Credentials {}
