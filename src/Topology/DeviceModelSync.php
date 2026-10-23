<?php

namespace LibreNMS\Plugins\MikroTik\Topology;

use Illuminate\Support\Facades\DB;

class DeviceModelSync
{
    public function sync(): array
    {
        $catalog = new DeviceModelCatalog();

        $devices = DB::table('devices')
            ->where(function ($query) {
                $query->where('os', 'routeros')
                    ->orWhere('os', 'mikrotik');
            })
            ->whereNotNull('hardware')
            ->where('hardware', '<>', '')
            ->get(['device_id', 'hardware']);

        $portsByDevice = DB::table('ports')
            ->whereIn('device_id', $devices->pluck('device_id')->all())
            ->orderBy('ifIndex')
            ->get(['device_id', 'ifName'])
            ->groupBy('device_id');

        $models = [];

        foreach ($devices as $device) {
            $model = $catalog->normalizeModel($device->hardware);

            if ($model === '') {
                continue;
            }

            if (! isset($models[$model])) {
                $models[$model] = [
                    'hardware' => $device->hardware,
                    'ports' => [],
                ];
            }

            foreach ($portsByDevice->get($device->device_id) ?? [] as $port) {
                $name = trim((string) $port->ifName);

                if (preg_match('/^(ether[0-9]+|sfp(?:-sfpplus)?[0-9]+|qsfp(?:plus)?[0-9]+)(?![0-9])/i', $name, $match)) {
                    $physical = strtolower($match[1]);
                    $models[$model]['ports'][$physical] = (object) [
                        'ifName' => $physical,
                    ];
                }
            }
        }

        $registered = 0;
        $newModels = [];

        $existingModels = DB::table('mikrotik_device_models')
            ->pluck('model')
            ->flip()
            ->all();

        foreach ($models as $entry) {
            if ($entry['ports'] === []) {
                continue;
            }

            $model = $catalog->normalizeModel($entry['hardware']);
            $isNew = ! array_key_exists($model, $existingModels);

            $catalog->register(
                $entry['hardware'],
                array_values($entry['ports'])
            );

            if ($isNew) {
                $newModels[] = $model;
                $existingModels[$model] = true;
            }

            $registered++;
        }

        // Retry image discovery for models without an image.
        $missingImages = DB::table('mikrotik_device_models')
            ->where(function ($query) {
                $query->whereNull('image_url')
                    ->orWhere('image_url', '');
            })
            ->pluck('model')
            ->all();

        $newModels = array_values(array_unique(
            array_merge($newModels, $missingImages)
        ));

        if ($newModels !== []) {
            try {
                $index = new MikroTikProductIndex();
                $products = $index->fetch();
                $discovery = new MikroTikImageDiscovery();

                $aliases = [
                    'C53UiG+5HPaxD2HPaxD' => 'hap_ax3',
                ];

                foreach ($newModels as $model) {
                    try {
                        $lookup = $aliases[$model] ?? $model;
                        $candidates = $index->findCandidates($lookup, $products);

                        if ($candidates === []) {
                            continue;
                        }

                        $best = $candidates[0];

                        if (isset($candidates[1]) &&
                            $candidates[1]['score'] === $best['score']) {
                            continue;
                        }

                        $images = $discovery->discover($best['url']);

                        if ($images === []) {
                            continue;
                        }

                        // Cache the image locally before selecting it.
                        $cachedPath = (new DeviceImageCache())->cache($images[0]['url']);

                        if ($cachedPath === null) {
                            continue;
                        }

                        DB::table('mikrotik_device_models')
                            ->where('model', $model)
                            ->whereNull('image_url')
                            ->update([
                                'source_url' => $best['url'],
                                'image_url' => $images[0]['url'],
                                'image_candidates' => json_encode($images),
                                'image_status' => 'selected',
                                'updated_at' => now(),
                            ]);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning(
                            'MikroTik automatic image discovery failed',
                            ['model' => $model, 'error' => $e->getMessage()]
                        );
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning(
                    'MikroTik product sitemap unavailable',
                    ['error' => $e->getMessage()]
                );
            }
        }

        return [
            'devices' => $devices->count(),
            'models' => $registered,
        ];
    }
}
