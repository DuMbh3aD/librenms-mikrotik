<?php

namespace LibreNMS\Plugins\MikroTik\Topology;

class DeviceImageCache
{
    private function directory(): string
    {
        $directory = storage_path('app/mikrotik/images');

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Cannot create MikroTik image cache directory');
        }

        return $directory;
    }

    public function filename(string $url): string
    {
        return hash('sha256', $url) . '.png';
    }

    public function path(string $url): string
    {
        return $this->directory() . '/' . $this->filename($url);
    }

    public function cache(string $url): ?string
    {
        $parts = parse_url($url);

        if (
            ! is_array($parts) ||
            ($parts['scheme'] ?? '') !== 'https' ||
            ($parts['host'] ?? '') !== 'cdn.mikrotik.com' ||
            ! preg_match(
                '~^/web-assets/rb_images/[a-zA-Z0-9_-]+\.png$~',
                $parts['path'] ?? ''
            ) ||
            isset($parts['query']) ||
            isset($parts['fragment']) ||
            isset($parts['user']) ||
            isset($parts['pass'])
        ) {
            return null;
        }

        $path = $this->path($url);

        if (is_file($path)) {
            return $path;
        }

        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_MAXFILESIZE => 8 * 1024 * 1024,
        ]);

        $content = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if (
            $status !== 200 ||
            ! is_string($content) ||
            strlen($content) > 8 * 1024 * 1024
        ) {
            return null;
        }

        $source = @imagecreatefromstring($content);

        if (! $source) {
            return null;
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        $scale = min(1, 500 / max($sourceWidth, $sourceHeight));
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));

        $thumbnail = imagecreatetruecolor($width, $height);

        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);

        $transparent = imagecolorallocatealpha($thumbnail, 0, 0, 0, 127);
        imagefill($thumbnail, 0, 0, $transparent);

        imagecopyresampled(
            $thumbnail,
            $source,
            0, 0, 0, 0,
            $width, $height,
            $sourceWidth, $sourceHeight
        );

        $temporary = tempnam($this->directory(), 'img_');

        if ($temporary === false) {
            return null;
        }

        $saved = imagepng($thumbnail, $temporary, 6);


        if (! $saved) {
            unlink($temporary);
            return null;
        }

        rename($temporary, $path);

        return $path;
    }
}
