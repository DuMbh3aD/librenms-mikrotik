<?php

namespace LibreNMS\Plugins\MikroTik\Monitoring;

use App\Models\Port;
use Illuminate\Support\Facades\DB;
use SnmpQuery;

class AdaptiveLinkPoller
{
    public function checkPort(int $deviceId, string $interface): ?string
    {
        $port = Port::query()
            ->where('device_id', $deviceId)
            ->where('ifName', $interface)
            ->where('deleted', 0)
            ->first();

        if (! $port || ! $port->device || ! $port->ifIndex) {
            return null;
        }

        try {
            $oid = "IF-MIB::ifOperStatus.{$port->ifIndex}";
            $response = SnmpQuery::device($port->device)->get($oid);

            if (! $response->isValid(true)) {
                return null;
            }

            $value = (string) $response->value($oid);

            return match ($value) {
                '1', 'up' => 'up',
                '2', 'down' => 'down',
                default => null,
            };
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }

    public function getPhysicalConnections()
    {
        return DB::table('mikrotik_connections')
            ->where('connection_type', 'lldp')
            ->where('status', 'resolved')
            ->whereNotNull('connection_key')
            ->whereNotNull('local_device_id')
            ->whereNotNull('remote_device_id')
            ->whereRaw("LOWER(local_interface) REGEXP '^(ether|sfp)'")
            ->whereRaw("LOWER(remote_interface) REGEXP '^(ether|sfp)'")
            ->get([
                'connection_key',
                'local_device_id',
                'local_interface',
                'remote_device_id',
                'remote_interface',
            ]);
    }

    public function run(): void
    {
        $now = now();

        $connections = $this->getPhysicalConnections();

        // Check each physical port only once per cycle.
        $portCache = [];

        foreach ($connections as $connection) {
            $key = $connection->connection_key;

            $localKey = $connection->local_device_id . ':' . $connection->local_interface;
            $remoteKey = $connection->remote_device_id . ':' . $connection->remote_interface;

            if (! array_key_exists($localKey, $portCache)) {
                $portCache[$localKey] = $this->checkPort(
                    $connection->local_device_id,
                    $connection->local_interface
                );
            }

            if (! array_key_exists($remoteKey, $portCache)) {
                $portCache[$remoteKey] = $this->checkPort(
                    $connection->remote_device_id,
                    $connection->remote_interface
                );
            }

            $localStatus = $portCache[$localKey];
            $remoteStatus = $portCache[$remoteKey];

            $state = DB::table('mikrotik_adaptive_polling')
                ->where('connection_key', $key)
                ->first();

            // Unknown SNMP result is not proof of a down port.
            if ($localStatus === null || $remoteStatus === null) {
                continue;
            }

            $status = ($localStatus === 'up' && $remoteStatus === 'up')
                ? 'up'
                : 'down';

            if (! $state) {
                DB::table('mikrotik_adaptive_polling')->insertOrIgnore([
                    'connection_key' => $key,
                    'last_status' => $status,
                    'down_since' => $status === 'down' ? $now : null,
                    'last_check_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                continue;
            }

            $update = [
                'last_status' => $status,
                'last_check_at' => $now,
                'updated_at' => $now,
            ];

            if ($status === 'up') {
                $update['down_since'] = null;
            } elseif ($state->last_status !== 'down' || $state->down_since === null) {
                $update['down_since'] = $now;
            }

            DB::table('mikrotik_adaptive_polling')
                ->where('connection_key', $key)
                ->update($update);
        }
    }
}
