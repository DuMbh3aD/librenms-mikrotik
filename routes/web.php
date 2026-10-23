<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

Route::middleware(['web', 'auth'])->get('/plugin/MikroTik/connections', function (Request $request) {
    abort_unless($request->user()->can('global-read'), 403);

    $protocol = (string) $request->query('protocol', '');
    $search = trim((string) $request->query('search', ''));
    $unresolved = $request->boolean('unresolved');

    $query = DB::table('mikrotik_connections as c')
        ->leftJoin('devices as ld', 'c.local_device_id', '=', 'ld.device_id')
        ->leftJoin('devices as rd', 'c.remote_device_id', '=', 'rd.device_id')
        ->select(
            'c.id',
            'c.connection_type as protocol',
            'c.status',
            'c.connection_layer',
            'c.local_interface as local_port',
            'c.remote_interface as remote_port',
            'c.remote_address as remote_hostname',
            'ld.hostname as local_device',
            'rd.hostname as remote_device'
        );

    if ($protocol !== '') {
        $query->where('c.connection_type', $protocol);
    }

    if ($search !== '') {
        $term = '%' . $search . '%';

        $query->where(function ($q) use ($term) {
            $q->where('ld.hostname', 'like', $term)
                ->orWhere('rd.hostname', 'like', $term)
                ->orWhere('ld.sysName', 'like', $term)
                ->orWhere('rd.sysName', 'like', $term)
                ->orWhere('c.local_interface', 'like', $term)
                ->orWhere('c.remote_interface', 'like', $term)
                ->orWhere('c.remote_address', 'like', $term);
        });
    }

    if ($unresolved) {
        $query->where('c.status', 'unresolved');
    }

    $connections = $query
        ->orderBy('c.id')
        ->paginate(50)
        ->withQueryString();

    $protocols = DB::table('mikrotik_connections')
        ->whereNotNull('connection_type')
        ->distinct()
        ->orderBy('connection_type')
        ->pluck('connection_type');

    return view('MikroTik::connections', compact(
        'connections',
        'protocols',
        'protocol',
        'search',
        'unresolved'
    ));
})->name('mikrotik.connections');

Route::middleware(['web', 'auth'])->post('/plugin/MikroTik/settings', function (Request $request) {
    abort_unless($request->user()->can('global-admin'), 403);

    $validated = $request->validate([
        'discovery_interval' => 'required|integer|in:1,5,10,15,30,60',
        'topology_refresh_interval' => 'required|integer|in:10,15,30,60,120',
        'discovery_enabled' => 'nullable|in:1',
        'lldp_enabled' => 'nullable|in:1',
    ]);

    $settings = [
        'discovery_enabled' => $request->boolean('discovery_enabled') ? '1' : '0',
        'discovery_interval' => (string) $validated['discovery_interval'],
        'topology_refresh_enabled' => $request->boolean('topology_refresh_enabled') ? '1' : '0',
        'topology_refresh_interval' => (string) $validated['topology_refresh_interval'],
        'lldp_enabled' => $request->boolean('lldp_enabled') ? '1' : '0',
    ];

    DB::transaction(function () use ($settings) {
        foreach ($settings as $key => $value) {
            DB::table('mikrotik_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now()]
            );
        }
    });

    return redirect(url('plugin/MikroTik') . '#mt-discovery')->with('discovery_saved', true);
})->name('mikrotik.settings.save');

Route::middleware(['web', 'auth'])->get('/plugin/MikroTik/topology', function (Request $request) {
    abort_unless($request->user()->can('global-read'), 403);

    $groups = DB::table('device_groups')
        ->orderBy('name')
        ->get(['id', 'name']);

    $groupId = (int) $request->query('group', 0);
    $groupName = $groupId
        ? (string) ($groups->firstWhere('id', $groupId)->name ?? 'Network')
        : 'Network';

    if ($groupId && ! $groups->contains('id', $groupId)) {
        abort(404);
    }

    $devices = $groupId
        ? DB::table('devices as d')
            ->join('device_group_device as gd', 'gd.device_id', '=', 'd.device_id')
            ->where('gd.device_group_id', $groupId)
            ->orderBy('d.hostname')
            ->get(['d.device_id', 'd.hostname', 'd.sysName', 'd.hardware'])
        : collect();

    $groupDeviceIds = $devices->pluck('device_id')->all();

    if ($groupDeviceIds) {
        $neighborIds = DB::table('mikrotik_connections')
            ->where('connection_type', 'lldp')
            ->where('status', 'resolved')
            ->whereRaw("LOWER(local_interface) REGEXP '^(ether|sfp)'")
            ->whereRaw("LOWER(remote_interface) REGEXP '^(ether|sfp)'")
            ->where(function ($q) use ($groupDeviceIds) {
                $q->whereIn('local_device_id', $groupDeviceIds)
                  ->orWhereIn('remote_device_id', $groupDeviceIds);
            })
            ->get(['local_device_id', 'remote_device_id'])
            ->flatMap(fn ($c) => [$c->local_device_id, $c->remote_device_id])
            ->unique()
            ->diff($groupDeviceIds)
            ->values()
            ->all();

        if ($neighborIds) {
            $devices = $devices->concat(
                DB::table('devices')
                    ->whereIn('device_id', $neighborIds)
                    ->get(['device_id', 'hostname', 'sysName', 'hardware'])
            );
        }
    }

    $positions = $groupId
        ? DB::table('mikrotik_topology_positions')
            ->where('group_id', $groupId)
            ->get(['device_id', 'x', 'y'])
            ->keyBy('device_id')
        : collect();

    $virtualPositions = $groupId
        ? DB::table('mikrotik_topology_virtual_positions')
            ->where('group_id', $groupId)
            ->get(['virtual_id', 'label', 'x', 'y'])
            ->keyBy('virtual_id')
        : collect();

    $deviceIds = $devices->pluck('device_id')->all();

    $connections = count($deviceIds) > 1
        ? DB::table('mikrotik_connections')
            ->where('connection_type', 'lldp')
            ->where('status', 'resolved')
            ->whereRaw("LOWER(local_interface) REGEXP '^(ether|sfp)'")
            ->whereRaw("LOWER(remote_interface) REGEXP '^(ether|sfp)'")
            ->whereIn('local_device_id', $deviceIds)
            ->whereIn('remote_device_id', $deviceIds)
            ->get([
                'id',
                'connection_key',
                'local_device_id',
                'local_interface',
                'remote_device_id',
                'remote_interface',
            ])
        : collect();

    $hiddenConnectionKeys = $groupId
        ? DB::table('mikrotik_hidden_connections')
            ->where('group_id', $groupId)
            ->pluck('connection_key')
            ->all()
        : [];

    // Port speed and operational status for topology link styling.
    $portStates = $deviceIds
        ? DB::table('ports')
            ->whereIn('device_id', $deviceIds)
            ->where('deleted', 0)
            ->get(['port_id', 'device_id', 'ifName', 'ifSpeed', 'ifOperStatus'])
            ->groupBy('device_id')
            ->map(fn ($ports) => $ports->map(fn ($port) => [
                'id' => $port->port_id,
                'name' => $port->ifName,
                'speed' => $port->ifSpeed,
                'status' => $port->ifOperStatus,
            ])->values()->all())
            ->all()
        : [];

    $deviceSvgs = [];

    if ($deviceIds) {
        $portsByDevice = DB::table('ports')
            ->whereIn('device_id', $deviceIds)
            ->orderBy('ifIndex')
            ->get(['device_id', 'ifName'])
            ->groupBy('device_id');

        $catalog = new \LibreNMS\Plugins\MikroTik\Topology\DeviceModelCatalog();

        foreach ($devices as $device) {
            $ports = ($portsByDevice->get($device->device_id) ?? collect())->all();

            $hardware = (string) ($device->hardware ?: $device->sysName);

            // Do not register non-network devices as MikroTik.
            if (stripos($hardware, 'PowerEdge') !== false) {
                continue;
            }

            $deviceSvgs[$device->device_id] = $catalog->register(
                $hardware,
                $ports
            );
        }
    }

    // MikroTik_DEVICE_STATUS_V1
    // Attach current LibreNMS status to topology devices.
    $deviceStatuses = DB::table('devices')
        ->select('device_id', 'status', 'disabled', 'ignore', 'uptime')
        ->get()
        ->keyBy('device_id');

    // MikroTik_ACTIVE_ALERTS_V1
    $activeAlertCounts = DB::table('alerts')
        ->where('state', 1)
        ->select('device_id')
        ->selectRaw('COUNT(*) as total')
        ->groupBy('device_id')
        ->pluck('total', 'device_id');

    // MikroTik_DEVICE_METRICS_V1
    // Latest polled CPU, memory and uptime values.
    $cpuMetrics = DB::table('processors')
        ->select('device_id')
        ->selectRaw('AVG(processor_usage) AS cpu')
        ->groupBy('device_id')
        ->pluck('cpu', 'device_id');

    $memoryMetrics = DB::table('mempools')
        ->where('mempool_total', '>', 0)
        ->select('device_id')
        ->selectRaw('SUM(mempool_used) / NULLIF(SUM(mempool_total), 0) * 100 AS memory')
        ->groupBy('device_id')
        ->pluck('memory', 'device_id');

    foreach ($devices as &$device) {
        $id = is_array($device)
            ? ($device['device_id'] ?? null)
            : ($device->device_id ?? null);

        $record = $deviceStatuses->get($id);

        $status = $record ? (int) $record->status : null;
        $disabled = $record ? (int) $record->disabled : 0;
        $ignored = $record ? (int) $record->ignore : 0;

        $activeAlerts = (int) ($activeAlertCounts[$id] ?? 0);

        $cpu = isset($cpuMetrics[$id]) ? round((float) $cpuMetrics[$id], 1) : null;
        $memory = isset($memoryMetrics[$id]) ? round((float) $memoryMetrics[$id], 1) : null;
        $uptime = $record ? (int) $record->uptime : null;

        if (is_array($device)) {
            $device['status'] = $status;
            $device['cpu_usage'] = $cpu;
            $device['memory_usage'] = $memory;
            $device['uptime_seconds'] = $uptime;
            $device['active_alerts'] = $activeAlerts;
            $device['disabled'] = $disabled;
            $device['ignore'] = $ignored;
        } else {
            $device->status = $status;
            $device->cpu_usage = $cpu;
            $device->memory_usage = $memory;
            $device->uptime_seconds = $uptime;
            $device->active_alerts = $activeAlerts;
            $device->disabled = $disabled;
            $device->ignore = $ignored;
        }
    }
    unset($device);

    // JSON data for live topology refresh.
    if ($request->query('refresh') === '1') {
        $refreshSettings = DB::table('mikrotik_settings')
            ->whereIn('key', [
                'topology_refresh_enabled',
                'topology_refresh_interval',
            ])
            ->pluck('value', 'key');

        return response()->json([
            'devices' => $devices,
            'connections' => $connections,
            'portStates' => $portStates,
            'deviceSvgs' => $deviceSvgs,
            'refreshEnabled' => ($refreshSettings['topology_refresh_enabled'] ?? '1') === '1',
            'refreshInterval' => (int) ($refreshSettings['topology_refresh_interval'] ?? 30),
        ]);
    }

    $mapOptions = \LibreNMS\Util\NetworkMapOptions::networkMap();
    $mapOptions['physics']['enabled'] = false;

    return view('MikroTik::topology', compact(
        'groups', 'groupId', 'groupName', 'devices', 'positions',
        'virtualPositions', 'connections', 'mapOptions', 'deviceSvgs',
        'portStates', 'hiddenConnectionKeys'
    ));
})->name('mikrotik.topology');


Route::middleware(['web', 'auth'])->post('/plugin/MikroTik/topology/connections/delete', function (Request $request) {
    abort_unless($request->user()->can('global-admin'), 403);

    $data = $request->validate([
        'group_id' => 'required|integer|exists:device_groups,id',
        'connection_key' => 'required|string|max:191',
    ]);

    $connection = DB::table('mikrotik_connections')
        ->where('connection_key', $data['connection_key'])
        ->where('connection_type', 'lldp')
        ->where('status', 'resolved')
        ->where(function ($query) use ($data) {
            $query->whereIn('local_device_id', function ($sub) use ($data) {
                $sub->select('device_id')
                    ->from('device_group_device')
                    ->where('device_group_id', $data['group_id']);
            })->orWhereIn('remote_device_id', function ($sub) use ($data) {
                $sub->select('device_id')
                    ->from('device_group_device')
                    ->where('device_group_id', $data['group_id']);
            });
        })
        ->first(['id']);

    abort_unless($connection, 404);

    DB::table('mikrotik_connections')
        ->where('id', $connection->id)
        ->delete();

    return response()->json(['success' => true]);
})->name('mikrotik.topology.connections.delete');

Route::middleware(['web', 'auth'])->post('/plugin/MikroTik/topology/positions', function (Request $request) {
    abort_unless($request->user()->can('global-admin'), 403);

    $data = $request->validate([
        'group_id' => 'required|integer|exists:device_groups,id',
        'device_id' => 'required|integer',
        'x' => 'required|numeric|between:-100000,100000',
        'y' => 'required|numeric|between:-100000,100000',
    ]);

    $groupIds = DB::table('device_group_device')
        ->where('device_group_id', $data['group_id'])
        ->pluck('device_id')->all();

    $belongsToGroup = in_array($data['device_id'], $groupIds);

    if (! $belongsToGroup) {
        $belongsToGroup = DB::table('mikrotik_connections')
            ->where('connection_type', 'lldp')
            ->where('status', 'resolved')
            ->whereRaw("LOWER(local_interface) REGEXP '^(ether|sfp)'")
            ->whereRaw("LOWER(remote_interface) REGEXP '^(ether|sfp)'")
            ->where(function ($q) use ($groupIds, $data) {
                $q->where(function ($x) use ($groupIds, $data) {
                    $x->whereIn('local_device_id', $groupIds)
                      ->where('remote_device_id', $data['device_id']);
                })->orWhere(function ($x) use ($groupIds, $data) {
                    $x->whereIn('remote_device_id', $groupIds)
                      ->where('local_device_id', $data['device_id']);
                });
            })->exists();
    }

    abort_unless($belongsToGroup, 422);

    DB::table('mikrotik_topology_positions')->updateOrInsert(
        [
            'group_id' => $data['group_id'],
            'device_id' => $data['device_id'],
        ],
        [
            'x' => $data['x'],
            'y' => $data['y'],
            'updated_at' => now(),
        ]
    );

    return response()->json(['success' => true]);
})->name('mikrotik.topology.positions.save');

Route::middleware(['web', 'auth'])->post('/plugin/MikroTik/topology/virtual-positions', function (Request $request) {
    abort_unless($request->user()->can('global-admin'), 403);

    $data = $request->validate([
        'group_id' => 'required|integer|exists:device_groups,id',
        'virtual_id' => 'required|string|max:191',
        'label' => 'required|string|max:191',
        'x' => 'required|numeric|between:-100000,100000',
        'y' => 'required|numeric|between:-100000,100000',
    ]);

    DB::table('mikrotik_topology_virtual_positions')->updateOrInsert(
        [
            'group_id' => $data['group_id'],
            'virtual_id' => $data['virtual_id'],
        ],
        [
            'label' => $data['label'],
            'x' => $data['x'],
            'y' => $data['y'],
            'updated_at' => now(),
            'created_at' => now(),
        ]
    );

    return response()->json(['success' => true]);
})->name('mikrotik.topology.virtual-positions.save');

Route::middleware(['web', 'auth'])->get('/plugin/MikroTik/device-models', function (Request $request) {
    abort_unless($request->user()->can('global-read'), 403);

    $models = DB::table('mikrotik_device_models')
        ->orderBy('manufacturer')
        ->orderBy('model')
        ->get();

    $catalog = new \LibreNMS\Plugins\MikroTik\Topology\DeviceModelCatalog();
    $productMatches = [];

    foreach ($models as $model) {
        $productMatches[$model->model] = $catalog->resolveProduct($model->model);
    }

    return view('MikroTik::device-models', compact('models', 'productMatches'));
})->name('mikrotik.device-models');

Route::middleware(['web', 'auth'])->post('/plugin/MikroTik/device-models/{id}/image', function (Request $request, int $id) {
    abort_unless($request->user()->can('global-admin'), 403);

    $data = $request->validate([
        'image_url' => 'required|string|max:2048',
    ]);

    $model = DB::table('mikrotik_device_models')->where('id', $id)->first();
    abort_unless($model, 404);

    $candidates = json_decode($model->image_candidates ?: '[]', true);
    $allowedUrls = collect(is_array($candidates) ? $candidates : [])
        ->pluck('url')
        ->filter()
        ->all();

    abort_unless(in_array($data['image_url'], $allowedUrls, true), 422);

    DB::table('mikrotik_device_models')
        ->where('id', $id)
        ->update([
            'image_url' => $data['image_url'],
            'image_status' => 'manual',
            'manually_edited' => true,
            'updated_at' => now(),
        ]);

    return redirect()->route('mikrotik.device-models')
        ->with('image_saved', true);
})->name('mikrotik.device-models.image.save');


Route::middleware(['web', 'auth'])->post('/plugin/MikroTik/device-models/{id}/product', function (Request $request, int $id) {
    abort_unless($request->user()->can('global-admin'), 403);

    $data = $request->validate([
        'source_url' => 'required|string|max:2048',
    ]);

    $model = DB::table('mikrotik_device_models')->where('id', $id)->first();
    abort_unless($model, 404);

    $catalog = new \LibreNMS\Plugins\MikroTik\Topology\DeviceModelCatalog();
    $resolved = $catalog->resolveProduct($model->model);

    $products = $resolved['candidates'] ?? [];
    if ($resolved['status'] === 'matched') {
        $products = [$resolved['product']];
    }

    $allowed = collect($products)->pluck('url')->filter()->all();
    abort_unless(in_array($data['source_url'], $allowed, true), 422);

    if ($model->source_url !== $data['source_url']) {
        DB::table('mikrotik_device_models')->where('id', $id)->update([
            'source_url' => $data['source_url'],
            'image_url' => null,
            'image_candidates' => null,
            'image_status' => 'pending',
            'manually_edited' => true,
            'updated_at' => now(),
        ]);
    }

    return redirect()->route('mikrotik.device-models')
        ->with('product_saved', true);
})->name('mikrotik.device-models.product.save');


Route::middleware(['web', 'auth'])->post('/plugin/MikroTik/device-models/{id}/layout', function (Request $request, int $id) {
    abort_unless($request->user()->can('global-admin'), 403);

    $data = $request->validate([
        'width' => 'required|integer|between:100,3000',
        'height' => 'required|integer|between:50,2000',
        'ports' => 'required|array',
        'body_shape' => 'sometimes|required|in:rounded,rectangle,circle',
        'label_x' => 'sometimes|required|numeric|min:0',
        'label_y' => 'sometimes|required|numeric|min:0',
    ]);

    $model = DB::table('mikrotik_device_models')->where('id', $id)->first();
    abort_unless($model, 404);

    try {
        (new \LibreNMS\Plugins\MikroTik\Topology\DeviceModelCatalog())
            ->saveLayout(
                $id,
                $data['ports'],
                $data['width'],
                $data['height'],
                $data['body_shape'] ?? null,
                isset($data['label_x']) ? (float) $data['label_x'] : null,
                isset($data['label_y']) ? (float) $data['label_y'] : null
            );
    } catch (\InvalidArgumentException $e) {
        return response()->json(['error' => $e->getMessage()], 422);
    }

    return response()->json(['success' => true]);
})->name('mikrotik.device-models.layout.save');


Route::middleware(['web', 'auth'])->get('/plugin/MikroTik/device-models/{id}/cached-image', function (Request $request, int $id) {
    abort_unless($request->user()->can('global-read'), 403);

    $model = DB::table('mikrotik_device_models')->where('id', $id)->first();
    abort_unless($model && ! empty($model->image_url), 404);

    $cache = new \LibreNMS\Plugins\MikroTik\Topology\DeviceImageCache();
    $path = $cache->path($model->image_url);

    abort_unless(is_file($path), 404);

    return response()->file($path, [
        'Content-Type' => 'image/png',
        'Cache-Control' => 'private, max-age=86400',
        'X-Content-Type-Options' => 'nosniff',
    ]);
})->name('mikrotik.device-models.cached-image');

