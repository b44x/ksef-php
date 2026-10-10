<?php

declare(strict_types=1);

namespace B4x\Ksef\Xml;

use B4x\Ksef\Exception\ConfigurationException;
use B4x\Ksef\Exception\SerializationException;

/** Validates XML documents against an XSD schema shipped with the library.
 *
 * @internal Not part of the public API: it may change in any release. Use {@see \B4x\Ksef\KsefClient}.
 */
final class SchemaValidator
{
    /**
     * @throws SerializationException listing every schema violation
     */
    public function assertValid(string $xml, string $schemaPath): void
    {
        $errors = $this->validate($xml, $schemaPath);
        if ($errors !== []) {
            throw new SerializationException('The document does not conform to the schema: ' . implode('; ', \array_slice($errors, 0, 10)), $errors);
        }
    }

    /**
     * @return list<string> schema violations, empty when valid
     */
    public function validate(string $xml, string $schemaPath): array
    {
        if (!is_file($schemaPath)) {
            throw new ConfigurationException(\sprintf('Schema file "%s" does not exist.', $schemaPath));
        }

        $document = SafeXml::load($xml);

        $previous = libxml_use_internal_errors(true);
        try {
            libxml_clear_errors();
            $valid = $document->schemaValidate($schemaPath);
            $errors = SafeXml::collectErrors();
        } finally {
            libxml_use_internal_errors($previous);
        }

        return $valid ? [] : ($errors !== [] ? $errors : ['The document is invalid.']);
    }
}
