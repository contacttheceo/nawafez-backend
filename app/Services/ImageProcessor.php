<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Compresses + resizes uploaded images using PHP's native GD extension.
 *
 * Why native GD instead of intervention/image?
 *   - GD is bundled with PHP everywhere (no composer dependency)
 *   - No vendor/ uploads needed on shared hosting
 *   - Lightweight, ~150 lines of code
 *
 * Usage:
 *   $processor = new ImageProcessor();
 *   $processor->processToFile($uploadedFile, $absoluteDestPath, [
 *       'max_width'  => 1920,   // resize if wider
 *       'max_height' => 1920,   // resize if taller
 *       'quality'    => 80,     // JPEG quality 0-100
 *       'format'     => 'jpg',  // jpg|png|webp|auto
 *   ]);
 */
class ImageProcessor
{
    /**
     * Process an uploaded image and save to disk.
     *
     * Returns the filename written (with extension), or null on failure.
     * On failure: falls back to a plain move() so the upload doesn't break.
     */
    public function processToFile(
        UploadedFile $upload,
        string $destDir,
        string $filenameBase,
        array $options = []
    ): ?string {
        $maxWidth  = $options['max_width']  ?? 1920;
        $maxHeight = $options['max_height'] ?? 1920;
        $quality   = $options['quality']    ?? 80;
        $format    = $options['format']     ?? 'auto';

        if (!function_exists('imagecreatefromjpeg')) {
            // GD not available — fall back to plain move
            return $this->fallbackMove($upload, $destDir, $filenameBase);
        }

        try {
            $srcPath = $upload->getRealPath();
            $info    = @getimagesize($srcPath);
            if (!$info) {
                return $this->fallbackMove($upload, $destDir, $filenameBase);
            }

            [$srcW, $srcH] = $info;
            $mime = $info['mime'] ?? '';

            // Decode source
            $src = $this->decode($srcPath, $mime);
            if (!$src) {
                return $this->fallbackMove($upload, $destDir, $filenameBase);
            }

            // Compute target dimensions (preserve aspect ratio; don't enlarge)
            $ratio = min($maxWidth / $srcW, $maxHeight / $srcH, 1.0);
            $dstW  = (int) round($srcW * $ratio);
            $dstH  = (int) round($srcH * $ratio);

            // Resize only if needed
            $dst = ($ratio < 1.0)
                ? $this->resize($src, $srcW, $srcH, $dstW, $dstH)
                : $src;

            // Determine output format
            $outputFormat = $format === 'auto'
                ? $this->bestFormatFor($mime)
                : $format;

            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }

            $filename = $filenameBase . '.' . $outputFormat;
            $destPath = $destDir . '/' . $filename;

            $ok = $this->encode($dst, $destPath, $outputFormat, $quality);

            // Cleanup GD resources
            if ($dst !== $src) imagedestroy($dst);
            imagedestroy($src);

            return $ok ? $filename : $this->fallbackMove($upload, $destDir, $filenameBase);

        } catch (\Throwable $e) {
            Log::warning("ImageProcessor: {$e->getMessage()}");
            return $this->fallbackMove($upload, $destDir, $filenameBase);
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function decode(string $path, string $mime)
    {
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp')
                ? @imagecreatefromwebp($path) : null,
            'image/gif'  => @imagecreatefromgif($path),
            default      => null,
        };
    }

    private function resize($src, int $sw, int $sh, int $dw, int $dh)
    {
        $dst = imagecreatetruecolor($dw, $dh);
        // Preserve PNG/WebP transparency
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $dw, $dh, $transparent);

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
        return $dst;
    }

    private function bestFormatFor(string $mime): string
    {
        // PNGs/GIFs may have transparency → keep as PNG
        // Everything else → JPEG (much smaller)
        return match ($mime) {
            'image/png', 'image/gif' => 'png',
            default                  => 'jpg',
        };
    }

    private function encode($img, string $path, string $format, int $quality): bool
    {
        return match ($format) {
            'jpg', 'jpeg' => imagejpeg($img, $path, $quality),
            'png'         => imagepng($img, $path, 9),       // 0-9 compression
            'webp'        => function_exists('imagewebp')
                ? imagewebp($img, $path, $quality)
                : imagejpeg($img, $path, $quality),
            default       => imagejpeg($img, $path, $quality),
        };
    }

    private function fallbackMove(UploadedFile $upload, string $destDir, string $filenameBase): ?string
    {
        try {
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            $ext  = $upload->getClientOriginalExtension() ?: 'jpg';
            $name = $filenameBase . '.' . $ext;
            $upload->move($destDir, $name);
            return $name;
        } catch (\Throwable $e) {
            Log::error("ImageProcessor fallback failed: {$e->getMessage()}");
            return null;
        }
    }
}
