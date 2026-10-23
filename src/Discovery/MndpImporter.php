<?php

namespace LibreNMS\Plugins\MikroTik\Discovery;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use SnmpQuery;

class MndpImporter
{
    public function dryRun(): array
    {
        $devices = Device::where('os', 'routeros')
            ->where('disabled', 0)
            ->get();

        $knownDevices = Device::query()
            ->get(['device_id', 'hostname', 'sysName']);

        $byIp = [];
        $byName = [];

        foreach ($knownDevices as $device) {
            $byIp[strtolower((string) $device->hostname)] = $device->device_id;

            if (! empty($device->sysName)) {
                $byName[strtolower((string) $device->sysName)][] = $device->device_id;
            }
        }

        $ports = DB::table('ports')
            ->get(['device_id', 'ifIndex', 'ifType'])
            ->keyBy(fn ($p) => $p->device_id . ':' . $p->ifIndex);

        $lldpPairs = [];

        foreach (DB::table('links')->where('protocol', 'lldp')->get() as $link) {
            $a = (int) $link->local_device_id;
            $b = (int) $link->remote_device_id;

            if ($a > 0 && $b > 0 && $a !== $b) {
                $pair = [$a, $b];
                sort($pair, SORT_NUMERIC);
                $lldpPairs[implode(':', $pair)] = true;
            }
        }

        $mndpPairs = [];
        $ethernetDirections = [];

        $result = [
            'devices_checked' => 0,
            'neighbors_found' => 0,
            'matched_by_ip' => 0,
            'matched_by_name' => 0,
            'unmatched' => 0,
            'logical_neighbors' => 0,
            'other_neighbors' => 0,
            'unique_device_pairs' => 0,
            'pairs_also_in_lldp' => 0,
            'pairs_not_in_lldp' => 0,
            'new_pairs_with_ethernet' => 0,
            'new_pairs_without_ethernet' => 0,
            'new_pairs_bidirectional_ethernet' => 0,
            'new_pairs_unidirectional_ethernet' => 0,
            'ethernet_observations' => 0,
            'wireless_observations' => 0,
            'other_interface_observations' => 0,
            'errors' => [],
        ];

        foreach ($devices as $device) {
            $result['devices_checked']++;

            try {
                $neighbors = SnmpQuery::device($device)
                    ->walk('MIKROTIK-MIB::mtxrNeighborTable')
                    ->mapTable(fn ($row, $id) => [
                        'id' => $id,
                        'identity' => $row['MIKROTIK-MIB::mtxrNeighborIdentity'] ?? null,
                        'ip' => $row['MIKROTIK-MIB::mtxrNeighborIpAddress'] ?? null,
                        'mac' => $row['MIKROTIK-MIB::mtxrNeighborMacAddress'] ?? null,
                        'interface' => $row['MIKROTIK-MIB::mtxrNeighborInterfaceID'] ?? null,
                    ]);

                $interfaces = SnmpQuery::device($device)
                    ->walk('MIKROTIK-MIB::mtxrInterfaceStatsName')
                    ->pluck();

                foreach ($neighbors as $neighbor) {
                    $result['neighbors_found']++;

                    $ip = strtolower((string) $neighbor['ip']);
                    $name = strtolower((string) $neighbor['identity']);

                    $remoteId = null;

                    if ($ip !== '0.0.0.0' && isset($byIp[$ip])) {
                        $remoteId = $byIp[$ip];
                        $result['matched_by_ip']++;
                    } elseif (
                        isset($byName[$name]) &&
                        count(array_unique($byName[$name])) === 1
                    ) {
                        $result['matched_by_name']++;
                        $remoteId = $byName[$name][0];
                    } else {
                        $result['unmatched']++;
                    }

                    $rawInterface = (string) $neighbor['interface'];
                    $interfaceId = ctype_xdigit($rawInterface)
                        ? hexdec($rawInterface)
                        : 0;

                    $port = $ports->get($device->device_id . ':' . $interfaceId);
                    $ifType = $port->ifType ?? null;

                    if ($ifType === 'ethernetCsmacd') {
                        $result['ethernet_observations']++;
                    } elseif ($ifType === 'ieee80211') {
                        $result['wireless_observations']++;
                    } else {
                        $result['other_interface_observations']++;
                    }

                    if ($remoteId !== null && (int) $remoteId !== (int) $device->device_id) {
                        $pair = [(int) $device->device_id, (int) $remoteId];
                        sort($pair, SORT_NUMERIC);
                        $pairKey = implode(':', $pair);
                        $mndpPairs[$pairKey] = ($mndpPairs[$pairKey] ?? false)
                            || $ifType === 'ethernetCsmacd';

                        if ($ifType === 'ethernetCsmacd') {
                            $ethernetDirections[$pairKey][(int) $device->device_id] = true;
                        }
                    }

                    $interfaceName = strtolower(
                        (string) ($interfaces[$interfaceId] ?? '')
                    );

                    if (
                        str_starts_with($interfaceName, 'vlan') ||
                        str_starts_with($interfaceName, 'bridge') ||
                        str_starts_with($interfaceName, 'wireguard')
                    ) {
                        $result['logical_neighbors']++;
                    } else {
                        $result['other_neighbors']++;
                    }
                }
            } catch (\Throwable $e) {
                $result['errors'][] = [
                    'device_id' => $device->device_id,
                    'hostname' => $device->hostname,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $result['unique_device_pairs'] = count($mndpPairs);
        $result['pairs_also_in_lldp'] = count(array_intersect_key($mndpPairs, $lldpPairs));
        $newPairs = array_diff_key($mndpPairs, $lldpPairs);
        $result['pairs_not_in_lldp'] = count($newPairs);
        $result['new_pairs_with_ethernet'] = count(array_filter($newPairs));
        $result['new_pairs_without_ethernet'] =
            count($newPairs) - $result['new_pairs_with_ethernet'];

        foreach ($newPairs as $pairKey => $hasEthernet) {
            if (! $hasEthernet) {
                continue;
            }

            $directions = count($ethernetDirections[$pairKey] ?? []);

            if ($directions === 2) {
                $result['new_pairs_bidirectional_ethernet']++;
            } elseif ($directions === 1) {
                $result['new_pairs_unidirectional_ethernet']++;
            }
        }

        return $result;
    }
}
