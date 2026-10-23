<?php

namespace LibreNMS\Plugins\MikroTik\Topology;

use Illuminate\Support\Facades\Http;

class MikroTikProductIndex
{
    public function resolveModel(string $model, array $products): array
    {
        $model = trim($model);

        if ($model === '') {
            return ['status' => 'not_found', 'candidates' => []];
        }

        $exact = [];
        $partial = [];

        foreach ($products as $product) {
            $code = trim($product['code'] ?? '');

            if ($code === '') {
                continue;
            }

            if (strcasecmp($code, $model) === 0) {
                $exact[] = $product;
            } elseif (stripos($code, $model) === 0) {
                $partial[] = $product;
            }
        }

        $candidates = count($exact) > 0 ? $exact : $partial;

        if (count($candidates) === 0) {
            return ['status' => 'not_found', 'candidates' => []];
        }

        if (count($candidates) === 1) {
            return [
                'status' => 'matched',
                'match_type' => count($exact) > 0 ? 'exact' : 'partial',
                'product' => $candidates[0],
            ];
        }

        return [
            'status' => 'ambiguous',
            'candidates' => $candidates,
        ];
    }

    public function buildIndex(bool $refresh = false): array
    {
        $cacheKey = 'mikrotik_plugin_product_index_v1';

        if (! $refresh) {
            $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);

            if (is_array($cached)) {
                return $cached;
            }
        }

        $products = $this->fetch();
        $index = [];

        foreach ($products as $product) {
            try {
                $code = $this->fetchProductCode($product['url']);

                if ($code !== null && $code !== '') {
                    $index[] = [
                        'code' => $code,
                        'slug' => $product['slug'],
                        'url' => $product['url'],
                    ];
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning(
                    'MikroTik product indexing failed',
                    ['url' => $product['url'], 'error' => $e->getMessage()]
                );
            }
        }

        if (count($index) < 100) {
            throw new \RuntimeException(
                'MikroTik product index incomplete; cache not updated'
            );
        }

        \Illuminate\Support\Facades\Cache::put(
            $cacheKey,
            $index,
            now()->addDays(7)
        );

        return $index;
    }

    public function fetchProductCode(string $url): ?string
    {
        $parts = parse_url($url);

        if (
            ! is_array($parts) ||
            ($parts['scheme'] ?? '') !== 'https' ||
            ($parts['host'] ?? '') !== 'mikrotik.com' ||
            ! preg_match('~^/product/[a-zA-Z0-9_+.-]+$~', $parts['path'] ?? '')
        ) {
            throw new \InvalidArgumentException('Invalid MikroTik product URL');
        }

        $response = Http::timeout(20)
            ->withoutRedirecting()
            ->get($url);

        if (! $response->successful()) {
            return null;
        }

        if (preg_match(
            "~compareProductsTrigger\('([^']+)'\)~",
            $response->body(),
            $matches
        )) {
            return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
        }

        return null;
    }

    public function findCandidates(string $model, array $products): array
    {
        $normalize = static fn (string $value): string =>
            strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $value));

        $modelKey = $normalize($model);
        if ($modelKey === '') {
            return [];
        }

        $candidates = [];

        foreach ($products as $product) {
            $slug = $product['slug'];
            $slugKey = $normalize($slug);

            if ($slugKey === $modelKey) {
                $score = 100;
            } elseif (str_starts_with($slugKey, $modelKey)) {
                $score = 80;
            } elseif (str_starts_with($modelKey, $slugKey) && strlen($slugKey) >= 5) {
                $score = 60;
            } else {
                continue;
            }

            $candidates[] = [
                'slug' => $slug,
                'url' => $product['url'],
                'score' => $score,
            ];
        }

        usort($candidates, static fn ($a, $b) =>
            $b['score'] <=> $a['score']
            ?: strcmp($a['slug'], $b['slug'])
        );

        return $candidates;
    }

    public function fetch(): array
    {
        $response = Http::timeout(30)
            ->get('https://mikrotik.com/sitemap.xml');

        if (! $response->successful()) {
            throw new \RuntimeException('Cannot download MikroTik sitemap');
        }

        $xml = simplexml_load_string(
            $response->body(),
            'SimpleXMLElement',
            LIBXML_NONET
        );

        if ($xml === false) {
            throw new \RuntimeException('Invalid MikroTik sitemap');
        }

        $products = [];

        foreach ($xml->url as $entry) {
            $url = trim((string) $entry->loc);
            $parts = parse_url($url);

            if (
                ($parts['scheme'] ?? '') !== 'https' ||
                ($parts['host'] ?? '') !== 'mikrotik.com' ||
                ! preg_match('~^/product/([a-zA-Z0-9_+.-]+)$~', $parts['path'] ?? '', $matches)
            ) {
                continue;
            }

            $products[] = [
                'slug' => $matches[1],
                'url' => $url,
            ];
        }

        return $products;
    }
}
