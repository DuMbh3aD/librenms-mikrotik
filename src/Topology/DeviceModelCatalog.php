<?php

namespace LibreNMS\Plugins\MikroTik\Topology;

use Illuminate\Support\Facades\DB;

class DeviceModelCatalog
{
    public function normalizeModel(string $hardware): string
    {
        return trim(preg_replace(
            '/\s+\d+\.\d+(?:\.\d+)?\s*\([^)]*\)\s*$/',
            '',
            $hardware
        ));
    }

    public function discoverImages(string $model): array
    {
        $record = DB::table('mikrotik_device_models')
            ->where('model', $model)
            ->first();

        if (! $record) {
            return ['status' => 'not_found'];
        }

        if (! empty($record->image_url)) {
            return ['status' => 'image_already_selected'];
        }

        if (! empty($record->image_candidates)) {
            return ['status' => 'already_discovered'];
        }

        if (empty($record->source_url)) {
            return ['status' => 'no_product'];
        }

        $images = (new MikroTikImageDiscovery())
            ->discover($record->source_url);

        DB::table('mikrotik_device_models')
            ->where('id', $record->id)
            ->update([
                'image_candidates' => json_encode($images),
                'image_status' => count($images) > 0 ? 'discovered' : 'not_found',
                'updated_at' => now(),
            ]);

        return [
            'status' => 'discovered',
            'count' => count($images),
        ];
    }

    public function resolveProduct(string $hardware): array
    {
        $model = $this->normalizeModel($hardware);

        $products = \Illuminate\Support\Facades\Cache::get(
            'mikrotik_plugin_product_index_v1'
        );

        if (! is_array($products)) {
            return ['status' => 'index_unavailable', 'candidates' => []];
        }

        return (new MikroTikProductIndex())->resolveModel($model, $products);
    }

    public function saveLayout(int $id, array $ports, int $width, int $height, ?string $bodyShape = null, ?float $labelX = null, ?float $labelY = null): void
    {
        if ($width < 100 || $width > 3000 || $height < 50 || $height > 2000) {
            throw new \InvalidArgumentException('Invalid layout dimensions.');
        }

        $record = DB::table('mikrotik_device_models')->where('id', $id)->first();

        if (! $record) {
            throw new \InvalidArgumentException('Device model not found.');
        }

        $bodyShape = $bodyShape ?? ($record->body_shape ?: 'rounded');

        if (! in_array($bodyShape, ['rounded', 'rectangle', 'circle'], true)) {
            throw new \InvalidArgumentException('Invalid body shape.');
        }

        $existingPorts = json_decode($record->ports ?: '{}', true);

        if (! is_array($existingPorts) ||
            array_keys($ports) !== array_keys($existingPorts)) {
            throw new \InvalidArgumentException('Port list cannot be changed.');
        }

        foreach ($ports as $name => $port) {
            if (! is_array($port) ||
                ! isset($port['x'], $port['y']) ||
                ! is_numeric($port['x']) ||
                ! is_numeric($port['y']) ||
                $port['x'] < 0 || $port['x'] > $width ||
                $port['y'] < 0 || $port['y'] > $height) {
                throw new \InvalidArgumentException('Invalid port position.');
            }

            $ports[$name] = [
                'x' => (float) $port['x'],
                'y' => (float) $port['y'],
                'interface' => $existingPorts[$name]['interface'],
            ];
        }

        $labelX = $labelX ?? (float) ($record->label_x ?? 16);
        $labelY = $labelY ?? (float) ($record->label_y ?? 23);

        if (! is_finite($labelX) || ! is_finite($labelY) ||
            $labelX < 0 || $labelX > $width ||
            $labelY < 0 || $labelY > $height) {
            throw new \InvalidArgumentException('Invalid label position.');
        }

        $svg = (new DeviceSvgGenerator())->renderLayout(
            $record->model,
            $ports,
            $width,
            $height,
            $bodyShape,
            $labelX,
            $labelY
        );

        DB::table('mikrotik_device_models')->where('id', $id)->update([
            'svg' => $svg,
            'ports' => json_encode($ports),
            'width' => $width,
            'height' => $height,
            'body_shape' => $bodyShape,
            'label_x' => $labelX,
            'label_y' => $labelY,
            'manually_edited' => true,
            'updated_at' => now(),
        ]);
    }

    public function register(string $hardware, array $ports): array
    {
        $model = $this->normalizeModel($hardware);

        $existing = DB::table('mikrotik_device_models')
            ->where('model', $model)
            ->first();

        // Existing models are never modified during synchronization.
        if ($existing) {
            return [
                'svg' => $existing->svg,
                'width' => $existing->width,
                'height' => $existing->height,
                'ports' => json_decode($existing->ports ?: '{}', true),
            ];
        }

        $generator = new DeviceSvgGenerator();
        $drawing = $generator->generate($model, $ports);

        DB::table('mikrotik_device_models')->updateOrInsert(
            ['model' => $model],
            [
                'manufacturer' => 'MikroTik',
                'body_shape' => 'generic',
                'svg' => $drawing['svg'],
                'ports' => json_encode($drawing['ports']),
                'width' => $drawing['width'],
                'height' => $drawing['height'],
                'updated_at' => now(),
                'created_at' => $existing->created_at ?? now(),
            ]
        );

        // Automatically populate only when no product is selected.
        if (! $existing || empty($existing->source_url)) {
            $resolved = $this->resolveProduct($model);

            if ($resolved['status'] === 'matched') {
                DB::table('mikrotik_device_models')
                    ->where('model', $model)
                    ->update([
                        'source_url' => $resolved['product']['url'],
                        'updated_at' => now(),
                    ]);
            }
        }

        return $drawing;
    }
}
