<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Qr;

use B4x\Ksef\Environment;
use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Qr\QrCodeRenderer;
use B4x\Ksef\Qr\VerificationLinks;
use DOMDocument;
use PHPUnit\Framework\TestCase;

final class QrCodeRendererTest extends TestCase
{
    public function testTheCodeIsAWellFormedSvgWithTheCaptionUnderIt(): void
    {
        $svg = (new QrCodeRenderer(300))->svg('https://qr.ksef.mf.gov.pl/invoice/5265877635/01-06-2026/abc', '5265877635-20260601-0100001AF629-15');

        $document = new DOMDocument();
        self::assertTrue($document->loadXML($svg));
        $root = $document->documentElement;
        self::assertNotNull($root);
        self::assertSame('300', $root->getAttribute('width'));
        self::assertSame('340', $root->getAttribute('height'));
        self::assertStringContainsString('>5265877635-20260601-0100001AF629-15</text>', $svg);
        self::assertStringContainsString('<path', $svg);
        self::assertSame(2, $document->getElementsByTagName('svg')->length, 'The code is nested in the captioned canvas.');
    }

    public function testWithoutACaptionOnlyTheCodeIsReturnedAndCaptionsAreEscaped(): void
    {
        $renderer = new QrCodeRenderer(200);

        self::assertStringNotContainsString('<text', $renderer->svg('https://example.test/a'));
        self::assertStringContainsString('&lt;b&gt;', $renderer->svg('https://example.test/a', '<b>'));
    }

    public function testBadInputIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        (new QrCodeRenderer(300))->svg('https://example.test/a', "two\nlines");
    }

    public function testLabelsFollowTheKsefRules(): void
    {
        $links = new VerificationLinks(Environment::Production);

        self::assertSame('OFFLINE', $links->label(null));
        self::assertSame('CERTYFIKAT', $links->certificateLabel());
    }
}
