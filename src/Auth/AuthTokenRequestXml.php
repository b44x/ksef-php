<?php

declare(strict_types=1);

namespace B4x\Ksef\Auth;

use B4x\Ksef\Xml\SchemaValidator;
use DOMDocument;
use DOMElement;

/**
 * Builds the `AuthTokenRequest` document that is signed for certificate authentication.
 *
 * @internal
 */
final class AuthTokenRequestXml
{
    public const NAMESPACE = 'http://ksef.mf.gov.pl/auth/token/2.0';

    public function __construct(
        private readonly SchemaValidator $validator = new SchemaValidator(),
        private readonly string $schemaPath = __DIR__ . '/../../resources/schemas/auth/authv2.xsd',
    ) {}

    public function build(
        string $challenge,
        ContextIdentifier $context,
        SubjectIdentifierType $subjectType,
        ?AllowedIps $allowedIps = null,
    ): string {
        $document = new DOMDocument('1.0', 'UTF-8');
        $root = $document->createElementNS(self::NAMESPACE, 'AuthTokenRequest');
        $document->appendChild($root);

        $this->append($document, $root, 'Challenge', $challenge);
        $contextNode = $document->createElementNS(self::NAMESPACE, 'ContextIdentifier');
        $root->appendChild($contextNode);
        $this->append($document, $contextNode, $context->type->value, $context->value);
        $this->append($document, $root, 'SubjectIdentifierType', $subjectType->value);

        if ($allowedIps !== null && !$allowedIps->isEmpty()) {
            $policy = $document->createElementNS(self::NAMESPACE, 'AuthorizationPolicy');
            $root->appendChild($policy);
            $ips = $document->createElementNS(self::NAMESPACE, 'AllowedIps');
            $policy->appendChild($ips);
            foreach ($allowedIps->addresses as $address) {
                $this->append($document, $ips, 'Ip4Address', $address);
            }
            foreach ($allowedIps->ranges as $range) {
                $this->append($document, $ips, 'Ip4Range', $range);
            }
            foreach ($allowedIps->masks as $mask) {
                $this->append($document, $ips, 'Ip4Mask', $mask);
            }
        }

        $xml = $document->saveXML();
        if ($xml === false) {
            throw new \B4x\Ksef\Exception\SerializationException('The authentication request could not be serialized.');
        }

        $this->validator->assertValid($xml, $this->schemaPath);

        return $xml;
    }

    private function append(DOMDocument $document, DOMElement $parent, string $name, string $value): void
    {
        $element = $document->createElementNS(self::NAMESPACE, $name);
        $element->appendChild($document->createTextNode($value));
        $parent->appendChild($element);
    }
}
