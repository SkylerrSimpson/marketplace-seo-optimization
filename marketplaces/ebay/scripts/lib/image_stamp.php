<?php

declare(strict_types=1);

/**
 * image_stamp.php — programmatic "2 Pack" badge stamping for a listing's main
 * image, via PHP's GD extension (requires the php8.2-gd system package —
 * confirmed available with FreeType/JPEG/PNG/WebP support after installing
 * it 2026-07-21). No external SDK/API needed for the stamping itself; getting
 * the stamped image back onto an eBay listing is a separate, unbuilt step
 * (eBay's Trading API UploadSiteHostedPictures) — see two_pack_rules.txt.
 *
 * PLACEHOLDER: badge color/style (currently solid charcoal + white text) is a
 * neutral default, not a final brand decision — Amazon-style corner badges are
 * the reference. Easy to swap via $bgColor/$textColor below.
 */

/**
 * Stamps $label (default "2 PACK") as a rounded badge in the bottom-right
 * corner of the image at $sourcePath, sized proportionally to the image's
 * shorter dimension so it looks right whether the source is a small 500px
 * gallery thumbnail or the full 1600px zoom image. Writes a JPEG (eBay's
 * standard listing-photo format) to $outputPath regardless of source format.
 */
function stampTwoPackBadge(string $sourcePath, string $outputPath, string $label = '2 PACK'): void
{
    $info = getimagesize($sourcePath);
    if ($info === false) {
        throw new \RuntimeException("not a readable image: {$sourcePath}");
    }
    [$width, $height, $type] = $info;

    $image = match ($type) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($sourcePath),
        IMAGETYPE_PNG => imagecreatefrompng($sourcePath),
        IMAGETYPE_WEBP => imagecreatefromwebp($sourcePath),
        default => throw new \RuntimeException("unsupported image type ({$type}) for {$sourcePath} — expected JPEG/PNG/WebP"),
    };

    $fontPath = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    if (!is_file($fontPath)) {
        throw new \RuntimeException("font not found: {$fontPath}");
    }

    $shortSide  = min($width, $height);
    $fontSize   = max(14, (int) round($shortSide * 0.06));
    $paddingX   = (int) round($fontSize * 0.9);
    $paddingY   = (int) round($fontSize * 0.55);
    $margin     = (int) round($shortSide * 0.035);
    $radius     = (int) round($fontSize * 0.5);

    // imagettfbbox measures the actual glyph box (bbox[1]/[5] can be
    // negative for descenders) — needed to size the badge to the real text,
    // not just the nominal font size.
    $bbox = imagettfbbox($fontSize, 0, $fontPath, $label);
    $textWidth  = abs($bbox[4] - $bbox[0]);
    $textHeight = abs($bbox[5] - $bbox[1]);

    $badgeWidth  = $textWidth + $paddingX * 2;
    $badgeHeight = $textHeight + $paddingY * 2;
    $badgeX1 = $width - $badgeWidth - $margin;
    $badgeY1 = $height - $badgeHeight - $margin;
    $badgeX2 = $badgeX1 + $badgeWidth;
    $badgeY2 = $badgeY1 + $badgeHeight;

    $bgColor   = imagecolorallocate($image, 24, 24, 24);    // PLACEHOLDER: neutral charcoal
    $textColor = imagecolorallocate($image, 255, 255, 255);

    drawFilledRoundedRect($image, (int) $badgeX1, (int) $badgeY1, (int) $badgeX2, (int) $badgeY2, $radius, $bgColor);

    $textX = $badgeX1 + $paddingX - $bbox[0];
    $textY = $badgeY1 + $paddingY - $bbox[1];
    imagettftext($image, $fontSize, 0, (int) $textX, (int) $textY, $textColor, $fontPath, $label);

    imagejpeg($image, $outputPath, 92);
    imagedestroy($image);
}

/**
 * GD has no native rounded-rectangle primitive — built from a center cross of
 * two filled rectangles plus four filled corner circles, the standard GD
 * technique for this.
 */
function drawFilledRoundedRect($image, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
{
    imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
    imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
    imagefilledellipse($image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
}
