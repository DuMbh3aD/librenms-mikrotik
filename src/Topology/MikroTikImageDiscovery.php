<?php

namespace LibreNMS\Plugins\MikroTik\Topology;

use Illuminate\Support\Facades\Http;

class MikroTikImageDiscovery
{
    public function discover(string $productUrl): array
    {
        $parts = parse_url($productUrl);

        if (
            ! is_array($parts) ||
            ($parts['scheme'] ?? '') !== 'https' ||
            ($parts['host'] ?? '') !== 'mikrotik.com' ||
            isset($parts['port']) ||
            isset($parts['user']) ||
            isset($parts['pass']) ||
            isset($parts['query']) ||
            isset($parts['fragment']) ||
            ! preg_match('~^/product/[a-zA-Z0-9_-]+/?$~', $parts['path'] ?? '')
        ) {
            throw new \InvalidArgumentException('Invalid MikroTik product URL');
        }

        $response = Http::withoutRedirecting()->timeout(20)->get($productUrl);

        if (! $response->successful()) {
            throw new \RuntimeException('MikroTik product page unavailable');
        }

        preg_match_all(
            '~https://cdn\.mikrotik\.com/web-assets/rb_images/(\d+)_(hi_res\.png|xl\.webp|lg\.webp)~',
            $response->body(),
            $matches,
            PREG_SET_ORDER
        );

        $images = [];

        foreach ($matches as $match) {
            $id = $match[1];
            $images[$id] = [
                'id' => $id,
                'url' => 'https://cdn.mikrotik.com/web-assets/rb_images/' . $id . '_hi_res.png',
            ];
        }

        return array_values($images);
    }
}
