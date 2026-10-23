<?php

namespace LibreNMS\Plugins\MikroTik\Discovery;

use Illuminate\Support\Facades\DB;

class LldpImporter
{
    public function dryRun(): array
    {
        $links = DB::table('links')
            ->where('protocol', 'lldp')
            ->orderBy('id')
            ->get();

        $connections = [];
        $sources = 0;
        $complete = 0;
        $incomplete = 0;

        foreach ($links as $link) {
            $localPort = (int) $link->local_port_id;
            $remotePort = (int) $link->remote_port_id;

            if ($localPort <= 0) {
                continue;
            }

            if ($remotePort > 0 && (int) $link->remote_device_id > 0) {
                // Both endpoints are known.
                // Normalize port ordering to merge reverse records.
                $ports = [$localPort, $remotePort];
                sort($ports, SORT_NUMERIC);

                $key = hash('sha256', 'lldp:ports:' . implode(':', $ports));
                $complete++;
            } else {
                // Incomplete connection: preserve the individual source.
                $key = hash('sha256', 'lldp:unresolved:' . $link->id);
                $incomplete++;
            }

            if (!isset($connections[$key])) {
                $connections[$key] = [
                    'key' => $key,
                    'type' => 'lldp',
                    'source_ids' => [],
                ];
            }

            $connections[$key]['source_ids'][] = $link->id;
            $sources++;
        }

        return [
            'lldp_records' => $links->count(),
            'complete_records' => $complete,
            'incomplete_records' => $incomplete,
            'unique_connections' => count($connections),
            'source_records' => $sources,
            'duplicate_records' => $sources - count($connections),
        ];
    }

    public function import(): array
    {
        $links = DB::table('links')
            ->where('protocol', 'lldp')
            ->orderBy('id')
            ->get();

        $ports = DB::table('ports')
            ->whereIn('port_id', $links->flatMap(fn ($link) => [
                $link->local_port_id,
                $link->remote_port_id,
            ])->filter()->unique()->values()->all())
            ->get()
            ->keyBy('port_id');

        $created = 0;
        $updated = 0;
        $sources = 0;

        DB::transaction(function () use ($links, $ports, &$created, &$updated, &$sources) {
            foreach ($links as $link) {
                $localPortId = (int) $link->local_port_id;
                $remotePortId = (int) $link->remote_port_id;

                if ($localPortId <= 0) {
                    continue;
                }

                $complete = $remotePortId > 0
                    && (int) $link->remote_device_id > 0;

                if ($complete) {
                    $pair = [$localPortId, $remotePortId];
                    sort($pair, SORT_NUMERIC);
                    $key = hash('sha256', 'lldp:ports:' . implode(':', $pair));
                } else {
                    $key = hash('sha256', 'lldp:unresolved:' . $link->id);
                }

                $localPort = $ports->get($localPortId);
                $remotePort = $ports->get($remotePortId);

                $data = [
                    'connection_type' => 'lldp',
                    'connection_layer' => 'discovery',
                    'local_device_id' => $link->local_device_id,
                    'local_interface' => $localPort->ifName ?? null,
                    'remote_device_id' => $complete ? $link->remote_device_id : null,
                    'remote_interface' => $complete
                        ? ($remotePort->ifName ?? null)
                        : $link->remote_port,
                    'remote_address' => $link->remote_hostname,
                    'source' => 'lldp',
                    'status' => $complete ? 'resolved' : 'unresolved',
                    'metadata' => json_encode([
                        'local_port_id' => $localPortId,
                        'remote_port_id' => $complete ? $remotePortId : null,
                        'remote_hostname' => $link->remote_hostname,
                        'remote_port' => $link->remote_port,
                    ]),
                    'updated_at' => now(),
                ];

                $existing = DB::table('mikrotik_connections')
                    ->where('connection_key', $key)
                    ->first();

                if ($existing) {
                    // Do not reverse direction for a reverse LLDP record.
                    // Preserve the originally selected endpoints.
                    $connectionId = $existing->id;
                    DB::table("mikrotik_connections")
                        ->where("id", $connectionId)
                        ->update(["updated_at" => now()]);
                    $updated++;
                } else {
                    $connectionId = DB::table('mikrotik_connections')
                        ->insertGetId(array_merge($data, [
                            'connection_key' => $key,
                            'created_at' => now(),
                        ]));
                    $created++;
                }

                DB::table('mikrotik_connection_sources')->updateOrInsert(
                    [
                        'source_type' => 'lldp',
                        'source_key' => 'librenms:links:' . $link->id,
                    ],
                    [
                        'connection_id' => $connectionId,
                        'source_data' => json_encode((array) $link),
                        'last_seen_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                $sources++;
            }
        });

        return [
            'created_connections' => $created,
            'existing_connections' => $updated,
            'processed_sources' => $sources,
        ];
    }

}
