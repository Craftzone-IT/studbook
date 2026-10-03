<?php

declare(strict_types=1);

namespace Studbook;

use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode as Generator;
use chillerlan\QRCode\QROptions;

/** Server-side QR codes as inline SVG (no external service). */
final class QrCode
{
    public static function svg(string $data): string
    {
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => false,
            'addQuietzone' => true,
            'drawLightModules' => false,
            'svgAddXmlHeader' => false,
            'markupDark' => '#000',
        ]);

        return (new Generator($options))->render($data);
    }
}
