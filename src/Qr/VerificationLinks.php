<?php

declare(strict_types=1);

namespace B4x\Ksef\Qr;

use B4x\Ksef\Auth\ContextIdentifier;
use B4x\Ksef\Environment;
use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Invoice\InvoiceDocument;
use B4x\Ksef\Support\KsefNumber;
use B4x\Ksef\Support\Nip;
use DateTimeInterface;

/**
 * Builds the verification links that go into the QR codes of an invoice visualisation.
 *
 * - KOD I (every invoice): lets anyone verify the invoice in KSeF and download its XML.
 * - KOD II (offline invoices only): proves the issuer's authenticity; signed with a KSeF *Offline* certificate.
 *
 * The SDK produces the links and the label; turning a link into an image is left to a QR library of
 * your choice (ISO/IEC 18004), for example `bacon/bacon-qr-code` - the links are plain strings.
 */
final class VerificationLinks
{
    private readonly string $base;

    public function __construct(Environment $environment)
    {
        $this->base = $environment->qrBaseUrl();
    }

    /**
     * KOD I for a prepared document. The issue date is the invoice's `P_1`.
     */
    public function invoiceUrl(Nip $sellerNip, DateTimeInterface $issueDate, InvoiceDocument $document): string
    {
        return $this->invoiceUrlFromHash($sellerNip, $issueDate, $document->hash());
    }

    /**
     * KOD I from the invoice's standard Base64 SHA-256 hash (as in `InvoiceDocument::hash()`).
     */
    public function invoiceUrlFromHash(Nip $sellerNip, DateTimeInterface $issueDate, string $base64Hash): string
    {
        return \sprintf('%s/invoice/%s/%s/%s', $this->base, $sellerNip->value, $issueDate->format('d-m-Y'), self::toBase64Url($base64Hash));
    }

    /**
     * KOD II for an offline invoice.
     *
     * @param ContextIdentifier $context the login context of the issuer
     * @param Nip $sellerNip NIP in `Podmiot1`
     */
    public function certificateUrl(ContextIdentifier $context, Nip $sellerNip, string $base64InvoiceHash, OfflineCertificate $certificate): string
    {
        $path = \sprintf(
            '%s/certificate/%s/%s/%s/%s/%s',
            preg_replace('#^https://#', '', $this->base),
            $context->type->value,
            $context->value,
            $sellerNip->value,
            $certificate->serialNumber(),
            self::toBase64Url($base64InvoiceHash),
        );

        return 'https://' . $path . '/' . $certificate->signBase64Url($path);
    }

    /**
     * Text printed under the QR code: the KSeF number once known, otherwise `OFFLINE`.
     */
    public function label(?string $ksefNumber): string
    {
        if ($ksefNumber === null) {
            return 'OFFLINE';
        }
        $error = KsefNumber::validate($ksefNumber);
        if ($error !== null) {
            throw new ValidationException(\sprintf('"%s" is not a valid KSeF number: %s', $ksefNumber, $error));
        }

        return $ksefNumber;
    }

    private static function toBase64Url(string $base64): string
    {
        if (base64_decode($base64, true) === false) {
            throw new ValidationException('The invoice hash must be Base64 encoded.');
        }

        return rtrim(strtr($base64, '+/', '-_'), '=');
    }
}
