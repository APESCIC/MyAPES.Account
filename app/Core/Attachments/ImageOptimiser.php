<?php

namespace App\Core\Attachments;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Resizes and re-encodes uploaded images (#552).
 *
 * Images larger than MAX_DIMENSION on either side are scaled down. Every
 * image is re-encoded with GD, which drops EXIF and other metadata such as
 * GPS location; the original bytes are never stored. If GD cannot read the
 * file, the upload is stored unchanged and a warning is logged.
 */
class ImageOptimiser
{
    public const MAX_DIMENSION = 2560;

    public const JPEG_QUALITY = 82;

    public const WEBP_QUALITY = 82;

    public const PNG_COMPRESSION = 6;

    public function optimise(UploadedFile $file): UploadedFile
    {
        $mime = $file->getMimeType();
        if (! function_exists('imagecreatefromstring') || ! in_array($mime, AttachmentLimits::SCREENSHOT_MIMES, true)) {
            if (! function_exists('imagecreatefromstring')) {
                Log::warning('Image optimisation skipped: the GD extension is not available.');
            }

            return $file;
        }

        $path = $file->getRealPath();
        $contents = is_string($path) && $path !== '' ? @file_get_contents($path) : false;
        $image = is_string($contents) && $contents !== '' ? @imagecreatefromstring($contents) : false;

        if (! $image instanceof GdImage) {
            Log::warning('Image optimisation skipped: the image could not be decoded.', ['mime' => $mime]);

            return $file;
        }

        if ($mime === 'image/jpeg' && is_string($path)) {
            $image = $this->applyExifOrientation($image, $path);
        }

        $image = $this->resize($image, $mime);
        $target = tempnam(sys_get_temp_dir(), 'myapes-img-');

        if ($target === false || ! $this->encode($image, $mime, $target)) {
            Log::warning('Image optimisation skipped: the image could not be re-encoded.', ['mime' => $mime]);

            return $file;
        }

        register_shutdown_function(static fn (): bool => File::delete($target));

        return new UploadedFile($target, $file->getClientOriginalName(), $mime, null, true);
    }

    private function resize(GdImage $image, string $mime): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest <= self::MAX_DIMENSION) {
            return $image;
        }

        $scale = self::MAX_DIMENSION / $longest;
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        if ($mime !== 'image/jpeg') {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 0, 0, 0, 127));
        }
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $resized;
    }

    private function encode(GdImage $image, string $mime, string $target): bool
    {
        return match ($mime) {
            'image/jpeg' => imagejpeg($image, $target, self::JPEG_QUALITY),
            'image/png' => $this->encodePng($image, $target),
            'image/webp' => function_exists('imagewebp') && imagewebp($image, $target, self::WEBP_QUALITY),
            default => false,
        };
    }

    private function encodePng(GdImage $image, string $target): bool
    {
        imagesavealpha($image, true);

        return imagepng($image, $target, self::PNG_COMPRESSION);
    }

    private function applyExifOrientation(GdImage $image, string $path): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated instanceof GdImage ? $rotated : $image;
    }
}
