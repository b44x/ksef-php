<?php

declare(strict_types=1);

namespace B4x\Ksef\Xml;

use B4x\Ksef\Exception\SerializationException;
use DOMDocument;

/**
 * Hardened XML loading.
 *
 * Documents containing a DOCTYPE are refused outright: KSeF forbids processing instructions and
 * has no use for DTDs, and refusing them removes the XXE and entity-expansion attack surface.
 * Network access is disabled and entities are never substituted.
 */
final class SafeXml
{
    private function __construct() {}

    public static function load(string $xml): DOMDocument
    {
        if ($xml === '') {
            throw new SerializationException('The XML document is empty.');
        }
        if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
            throw new SerializationException('XML documents with a DOCTYPE declaration are not accepted.');
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = true;

        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA);
            $errors = self::collectErrors();
        } finally {
            libxml_use_internal_errors($previous);
        }

        if (!$loaded || $document->documentElement === null) {
            throw new SerializationException('The XML document is not well-formed: ' . implode('; ', $errors), $errors);
        }

        return $document;
    }

    /**
     * @return list<string>
     */
    public static function collectErrors(): array
    {
        $messages = [];
        foreach (libxml_get_errors() as $error) {
            $messages[] = \sprintf('line %d: %s', $error->line, trim($error->message));
        }
        libxml_clear_errors();

        return $messages;
    }
}
