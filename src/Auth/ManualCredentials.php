<?php

declare(strict_types=1);

namespace B4x\Ksef\Auth;

/**
 * Authentication where the signature is made outside the SDK: with a qualified signature app, a trusted profile
 * (Profil Zaufany / ePUAP), a smart card service or any other tool that produces an XAdES signature.
 *
 * It is a two-step flow, because the person signing is usually not in the same request:
 *
 * 1. `$request = $ksef->createAuthenticationRequest();` returns the `AuthTokenRequest` XML to sign (valid 10 minutes);
 * 2. the signer returns it with an enveloped XAdES signature;
 * 3. `$ksef->authenticateWithSignedRequest($signedXml);` hands it to KSeF and completes the login.
 *
 * Combine it with a {@see TokenStore} and the resulting tokens serve the following requests (until the refresh token
 * expires), so the person signs once, not on every request. Calling the API before step 3 throws
 * {@see \B4x\Ksef\Exception\ManualSignatureRequiredException}.
 */
final readonly class ManualCredentials implements Credentials
{
    public function __construct(public SubjectIdentifierType $identifiedBy = SubjectIdentifierType::CertificateSubject) {}
}
