<?php

declare(strict_types=1);

require_once __DIR__ . '/file.php';
require_once __DIR__ . '/logger.php';

class Utils {

    /**
     * Produces a recursive associative diff between two arrays.
     * Returns an array of changed/added/removed keys with their new values.
     */
    public static function arrayDiffRecursive(array $old, array $new): array
    {
        $diff = [];

        // Keys present only in new data
        foreach (array_diff_key($new, $old) as $key => $val) {
            $diff[$key] = $val;
        }

        // Keys present only in old data (removed)
        foreach (array_diff_key($old, $new) as $key => $_) {
            $diff[$key] = '[REMOVED]';
        }

        // Keys present in both but changed
        foreach (array_intersect_key($new, $old) as $key => $val) {
            if (is_array($val) && is_array($old[$key])) {
                $subDiff = self::arrayDiffRecursive($old[$key], $val);
                if ($subDiff !== []) {
                    $diff[$key] = $subDiff;
                }
            } elseif ($val !== $old[$key]) {
                $diff[$key] = $val;
            }
        }

        return $diff;
    }

    /**
     * Creates a JPEG thumbnail of a source image with a fixed width 256px,
     * maintaining aspect ratio, saving it to the destination path.
     * @param string $srcImgPath  Relative or absolute path within FTP root for source image.
     * @param string $destImgPath Relative or absolute path within FTP root for thumbnail.
     * @param int    $thumbWidth  Width of thumbnail in pixels (default: 256).
     * @param int    $dirMode     Directory permissions (default: 0777).
     * @param int    $jpegQuality JPEG quality 0-100 (default: 85).
     * @throws RuntimeException if any step fails.
     */
    public static function createImageThumbnail(
        string $srcImgPath,
        string $destImgPath,
        int $thumbWidth = 256,
        int $dirMode = 0777,
        int $jpegQuality = 85
    ): void {
        $srcAbsPath = File::ftpDir($srcImgPath);

        if (!is_file($srcAbsPath) || !is_readable($srcAbsPath)) {
            Logger::error("createImageThumbnail: Source image not found or unreadable: {$srcAbsPath}");
            throw new \RuntimeException("Source image missing or unreadable: {$srcAbsPath}");
        }

        $srcInfo = @getimagesize($srcAbsPath);
        if ($srcInfo === false) {
            Logger::error("createImageThumbnail: Failed to get image size for {$srcAbsPath}");
            throw new \RuntimeException("Unable to get image size: {$srcAbsPath}");
        }

        // Detect image type and create source image resource
        switch ($srcInfo[2]) {
            case IMAGETYPE_GIF:
                $srcImage = @imagecreatefromgif($srcAbsPath);
                break;
            case IMAGETYPE_JPEG:
                $srcImage = @imagecreatefromjpeg($srcAbsPath);
                break;
            case IMAGETYPE_PNG:
                $srcImage = @imagecreatefrompng($srcAbsPath);
                break;
            default:
                Logger::error("createImageThumbnail: Unsupported image type for {$srcAbsPath}");
                throw new \RuntimeException("Unsupported image type for thumbnail: {$srcAbsPath}");
        }

        if ($srcImage === false) {
            Logger::error("createImageThumbnail: Failed to load image resource from {$srcAbsPath}");
            throw new \RuntimeException("Failed to load image resource: {$srcAbsPath}");
        }

        // Calculate scaled height keeping aspect ratio
        $srcWidth = $srcInfo[0];
        $srcHeight = $srcInfo[1];
        $thumbHeight = (int) floor($thumbWidth * $srcHeight / $srcWidth);

        $destImage = imagecreatetruecolor($thumbWidth, $thumbHeight);

        // Preserve transparency for PNG and GIF by filling with transparent color
        if (in_array($srcInfo[2], [IMAGETYPE_PNG, IMAGETYPE_GIF], true)) {
            imagecolortransparent($destImage, imagecolorallocatealpha($destImage, 0, 0, 0, 127));
            imagealphablending($destImage, false);
            imagesavealpha($destImage, true);
        }

        // Resample (resize) the image
        $resampled = imagecopyresampled(
            $destImage, $srcImage,
            0, 0, 0, 0,
            $thumbWidth, $thumbHeight,
            $srcWidth, $srcHeight
        );

        if (!$resampled) {
            imagedestroy($srcImage);
            imagedestroy($destImage);
            Logger::error("createImageThumbnail: Failed to resample image for {$srcAbsPath}");
            throw new \RuntimeException("Failed to resize image: {$srcAbsPath}");
        }

        // Prepare destination directory
        $destAbsPath = File::ftpDir($destImgPath);
        $destAbsDir = dirname($destAbsPath);
        if (!is_dir($destAbsDir)) {
            if (!mkdir($destAbsDir, $dirMode, true) && !is_dir($destAbsDir)) {
                imagedestroy($srcImage);
                imagedestroy($destImage);
                Logger::error("createImageThumbnail: Failed to create directory {$destAbsDir}");
                throw new \RuntimeException("Failed to create directory: {$destAbsDir}");
            }
        }

        // Save thumbnail as JPEG with given quality
        $saveSuccess = imagejpeg($destImage, $destAbsPath, $jpegQuality);
        imagedestroy($srcImage);
        imagedestroy($destImage);

        if (!$saveSuccess) {
            Logger::error("createImageThumbnail: Failed to save thumbnail to {$destAbsPath}");
            throw new \RuntimeException("Failed to save thumbnail to: {$destAbsPath}");
        }

        Logger::info("Thumbnail created: {$destAbsPath} (width: {$thumbWidth}px, height: {$thumbHeight}px)");
    }
}