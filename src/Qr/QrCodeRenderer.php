<?php

declare(strict_types=1);

namespace B4x\Ksef\Qr;

use B4x\Ksef\Exception\ConfigurationException;
use B4x\Ksef\Exception\ValidationException;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Draws the verification links of {@see VerificationLinks} as SVG images with the caption KSeF requires under the code
 * (the KSeF number or `OFFLINE` under KOD I, `CERTYFIKAT` under KOD II). Requires `bacon/bacon-qr-code`.
 *
 * SVG scales without loss and embeds in HTML, PDF generators and mail templates. To get a PNG, convert the SVG with
 * the tool you already use for the visualisation (Imagick, a headless browser, Inkscape).
 */
final class QrCodeRenderer
{
    private const MAX_CAPTION_LENGTH = 64;

    /**
     * @param int $size width and height of the code in pixels (the caption adds a band below it)
     */
    public function __construct(private readonly int $size = 300)
    {
        if ($size < 100 || $size > 2000) {
            throw new ValidationException('The QR code size must be between 100 and 2000 pixels.');
        }
    }

    /**
     * @param string $content the link to encode
     * @param string|null $caption text printed centred under the code
     *
     * @throws ConfigurationException when bacon/bacon-qr-code is not installed
     */
    public function svg(string $content, ?string $caption = null): string
    {
        if (!class_exists(Writer::class)) {
            throw new ConfigurationException('Drawing QR codes needs bacon/bacon-qr-code: composer require bacon/bacon-qr-code');
        }
        if ($content === '') {
            throw new ValidationException('There is nothing to encode.');
        }
        if ($caption !== null && (\strlen($caption) > self::MAX_CAPTION_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $caption) === 1)) {
            throw new ValidationException('The caption must be a single line of at most 64 characters.');
        }

        $renderer = new ImageRenderer(new RendererStyle($this->size, 4), new SvgImageBackEnd());
        $code = (new Writer($renderer))->writeString($content, 'UTF-8', ErrorCorrectionLevel::M());
        if ($caption === null) {
            return $code;
        }

        // Nest the code in a taller canvas and add the caption beneath it.
        $inner = preg_replace('/^<\?xml[^>]*\?>\s*/', '', $code) ?? $code;
        $inner = preg_replace('/^<svg\b/', '<svg x="0" y="0"', $inner, 1) ?? $inner;
        $band = 40;
        $fontSize = max(10, min(20, (int) floor($this->size / (max(1, \strlen($caption)) * 0.62))));

        return \sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<svg xmlns="http://www.w3.org/2000/svg" version="1.1" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d">'
            . '<rect x="0" y="0" width="%1$d" height="%2$d" fill="#ffffff"/>%3$s'
            . '<text x="%4$s" y="%5$d" text-anchor="middle" font-family="monospace" font-size="%6$d" fill="#000000">%7$s</text></svg>',
            $this->size,
            $this->size + $band,
            $inner,
            (string) ($this->size / 2),
            $this->size + (int) ($band * 0.62),
            $fontSize,
            htmlspecialchars($caption, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
        );
    }
}
