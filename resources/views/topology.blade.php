@extends('layouts.librenmsv1')

@section('title', 'MikroTik Network Map')

@section('content')
<div class="container-fluid">
    <h3>MikroTik Network Map</h3>

    <form method="GET" action="{{ route('mikrotik.topology') }}" style="margin-bottom:15px">
        <label for="mt-group">Device Group:</label>
        <select id="mt-group" name="group" onchange="this.form.submit()" class="form-control" style="width:300px;display:inline-block">
            <option value="0">-- Select group --</option>
            @foreach($groups as $group)
                <option value="{{ $group->id }}" {{ $groupId == $group->id ? 'selected' : '' }}>
                    {{ $group->name }}
                </option>
            @endforeach
        </select>
    </form>

    
<div style="position:relative">
    <div style="position:absolute;top:12px;right:12px;z-index:10;
                background:rgba(20,29,39,.94);border:1px solid #405063;
                border-radius:8px;padding:10px 14px;color:#e2e8f0;
                font-size:12px;pointer-events:none">
        <div style="font-weight:600;margin-bottom:6px">Link Speeds</div>
        <div style="display:grid;grid-template-columns:20px auto;gap:5px 9px;align-items:center">
            <span style="border-top:3px solid #ef4444"></span><span>DOWN</span>
            <span style="border-top:3px solid #1558b0"></span><span>10 Gbps+</span>
            <span style="border-top:3px solid #38bdf8"></span><span>2.5 Gbps+</span>
            <span style="border-top:3px solid #22c55e"></span><span>1 Gbps</span>
            <span style="border-top:3px solid #eab308"></span><span>100 Mbps</span>
            <span style="border-top:3px solid #f97316"></span><span>Below 100 Mbps</span>
            <span style="border-top:3px solid #94a3b8"></span><span>Unknown</span>
        </div>
    </div>
    <div id="mt-network" style="height:calc(100vh - 230px);min-height:600px;border:1px solid #405063;background:#17212b;border-radius:8px"></div>
</div>
</div>

<script src="{{ asset('js/vis-data.min.js') }}"></script>
<script src="{{ asset('js/vis-network.min.js') }}"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const container = document.getElementById('mt-network');

    const devices = @json($devices);
    const deviceSvgs = @json($deviceSvgs);
    const positions = @json($positions);
    const groupId = @json($groupId);
    const groupName = @json($groupName);
    const virtualPositions = @json($virtualPositions);
    const saveUrl = @json(route('mikrotik.topology.positions.save'));
    const csrfToken = @json(csrf_token());

    const columns = Math.max(1, Math.ceil(Math.sqrt(devices.length)));

    const nodes = new vis.DataSet(devices.map((device, index) => {
        const saved = positions[device.device_id];

        const drawing = deviceSvgs[device.device_id];
        const svgUrl = drawing
            ? 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(drawing.svg)
            : null;

        return {
            id: device.device_id,
            label: device.sysName || device.hostname,
            title: device.hostname,
            shape: svgUrl ? 'image' : 'box',
            ...(svgUrl ? {
                image: svgUrl,
                size: 65,
                shapeProperties: {useImageSize: true}
            } : {}),
            x: saved ? Number(saved.x) : (index % columns) * 220,
            y: saved ? Number(saved.y) : Math.floor(index / columns) * 100
        };
    }));

    const connections = @json($connections);
    const portStates = @json($portStates);

    function physicalName(value) {
        const match = String(value || '').trim().toLowerCase()
            .match(/^(ether\d+|sfp(?:-sfpplus)?\d+|qsfp(?:plus|28)?\d+)(?=$|[-_])/);
        return match ? match[1] : null;
    }

    function getPortState(deviceId, interfaceName) {
        const requested = String(interfaceName || '').toLowerCase();
        const physical = physicalName(requested);

        return (portStates[deviceId] || []).find(port => {
            const name = String(port.name || '').toLowerCase();
            return name === requested ||
                (physical && physicalName(name) === physical);
        }) || null;
    }

    function linkStyle(connection) {
        const local = getPortState(connection.local_device_id, connection.local_interface);
        const remote = getPortState(connection.remote_device_id, connection.remote_interface);

        const states = [local, remote].filter(Boolean);
        const down = states.some(p => String(p.status).toLowerCase() === 'down');

        const speeds = states
            .map(p => Number(p.speed))
            .filter(speed => Number.isFinite(speed) && speed > 0);

        const speed = speeds.length ? Math.min(...speeds) : null;

        if (down) return {color: '#ef4444', width: 4};
        if (speed === null) return {color: '#94a3b8', width: 3};
        if (speed >= 10000000000) return {color: '#1558b0', width: 4};
        if (speed >= 2500000000) return {color: '#38bdf8', width: 4};
        if (speed >= 1000000000) return {color: '#22c55e', width: 4};
        if (speed >= 100000000) return {color: '#eab308', width: 4};
        return {color: '#f97316', width: 4};
    }

    // Group LLDP neighbors by the local physical port.
    const groupsByPort = new Map();

    connections.forEach(c => {
        const key = c.local_device_id + ':' + c.local_interface;
        if (!groupsByPort.has(key)) groupsByPort.set(key, []);
        groupsByPort.get(key).push(c);
    });

    const virtualNodes = [];
    const virtualEdges = [];
    const hiddenConnections = new Set();
    const assignedDevices = new Set();

    // Consider ports with at least three distinct neighbors first.
    const candidates = [...groupsByPort.entries()]
        .filter(([key, links]) =>
            new Set(links.map(c => Number(c.remote_device_id))).size >= 3
        )
        .sort((a, b) => b[1].length - a[1].length);

    candidates.forEach(([key, links]) => {
        const parentId = Number(links[0].local_device_id);
        const children = [...new Set(links.map(c => Number(c.remote_device_id)))]
            .filter(id => id !== parentId && !assignedDevices.has(id));

        if (children.length < 3) return;

        const childSet = new Set(children);
        const mutualLinks = connections.filter(c =>
            childSet.has(Number(c.local_device_id)) &&
            childSet.has(Number(c.remote_device_id))
        );

        // Require evidence that the candidate devices see one another.
        if (mutualLinks.length < 2) return;

        const parent = nodes.get(parentId);
        if (!parent) return;

        // Stable ID derived from the parent device and local port.
        const virtualId = 'virtual:' + key;
        const savedVirtual = virtualPositions[virtualId];
        const labelBase = String(groupName || 'Network')
            .trim()
            .replace(/[^a-zA-Z0-9_-]+/g, '-')
            .replace(/^-+|-+$/g, '') || 'Network';
        const virtualIndex = virtualNodes.length + 1;
        const virtualLabel = savedVirtual?.label || (labelBase + '-UNMAN_' + virtualIndex);
        const x = savedVirtual ? Number(savedVirtual.x) : Number(parent.x || 0) + 150;
        const y = savedVirtual ? Number(savedVirtual.y) : Number(parent.y || 0) + 80;

        virtualNodes.push({
            id: virtualId,
            label: virtualLabel,
            title: 'Virtual switch — ' + (links[0].local_interface || ''),
            shape: 'box',
            color: {background: '#dddddd', border: '#777777'},
            x,
            y
        });

        virtualEdges.push({
            id: 'uplink:' + key,
            from: parentId,
            to: virtualId,
            title: links[0].local_interface || '?',
            color: {
                color: linkStyle(links[0]).color,
                highlight: linkStyle(links[0]).color
            },
            width: linkStyle(links[0]).width,
            smooth: false
        });

        children.forEach(childId => {
            assignedDevices.add(childId);
            const childConnection = links.find(c =>
                Number(c.remote_device_id) === childId
            );
            const childPort = childConnection
                ? getPortState(childId, childConnection.remote_interface)
                : null;
            const childStyle = childPort
                ? linkStyle({
                    local_device_id: childId,
                    local_interface: childConnection.remote_interface,
                    remote_device_id: null,
                    remote_interface: null
                })
                : {color: '#94a3b8', width: 3};

            virtualEdges.push({
                id: 'downlink:' + key + ':' + childId,
                from: virtualId,
                to: childId,
                color: {
                    color: childStyle.color,
                    highlight: childStyle.color
                },
                width: childStyle.width,
                smooth: false
            });
        });

        connections.forEach(c => {
            const localId = Number(c.local_device_id);
            const remoteId = Number(c.remote_device_id);

            if (
                (localId === parentId && childSet.has(remoteId)) ||
                (remoteId === parentId && childSet.has(localId)) ||
                (childSet.has(localId) && childSet.has(remoteId))
            ) {
                hiddenConnections.add(c.id);
            }
        });
    });

    nodes.add(virtualNodes);

    const normalEdges = connections
        .filter(c => !hiddenConnections.has(c.id))
        .map(connection => ({
            id: connection.id,
            from: Number(connection.local_device_id),
            to: Number(connection.remote_device_id),
            title: (connection.local_interface || '?') + ' ↔ ' +
                   (connection.remote_interface || '?'),
            color: {
                color: linkStyle(connection).color,
                highlight: linkStyle(connection).color
            },
            width: linkStyle(connection).width,
            smooth: false
        }));

    function findPhysicalPort(deviceId, interfaceName) {
        const drawing = deviceSvgs[deviceId];
        if (!drawing?.ports) return null;

        const requested = String(interfaceName || '').trim().toLowerCase();

        if (!/^(ether\d+|sfp(?:-sfpplus)?\d+|qsfp(?:plus|28)?\d+)(?=$|[-_])/.test(requested)) {
            return null;
        }

        return Object.entries(drawing.ports).find(([name, data]) => {
            const candidates = [name, data.interface || ''];

            return candidates.some(candidate => {
                const value = String(candidate).trim().toLowerCase();
                return value === requested ||
                    value.startsWith(requested + '-') ||
                    value.startsWith(requested + '_') ||
                    requested.startsWith(value + '-') ||
                    requested.startsWith(value + '_');
            });
        }) || null;
    }

    // Temporary diagnostic: show unmatched physical ports.
    console.table(connections.map(c => ({
        localDevice: c.local_device_id,
        localInterface: c.local_interface,
        localPortFound: !!findPhysicalPort(c.local_device_id, c.local_interface),
        remoteDevice: c.remote_device_id,
        remoteInterface: c.remote_interface,
        remotePortFound: !!findPhysicalPort(c.remote_device_id, c.remote_interface)
    })).filter(c => !c.localPortFound || !c.remotePortFound));

    // Draw only links whose physical ports are both identified.
    const physicalLinks = normalEdges.filter(edge => {
        const connection = connections.find(c => c.id === edge.id);
        return connection &&
            findPhysicalPort(connection.local_device_id, connection.local_interface) &&
            findPhysicalPort(connection.remote_device_id, connection.remote_interface);
    });

    const physicalLinkIds = new Set(physicalLinks.map(edge => edge.id));

    const edges = new vis.DataSet([
        ...normalEdges.filter(edge => !physicalLinkIds.has(edge.id))
    ]);

    const mapOptions = @json($mapOptions);

    // Preserve manually arranged positions.
    mapOptions.physics = {enabled: false};
    mapOptions.edges = {
        ...(mapOptions.edges || {}),
        arrows: {to: {enabled: false}, from: {enabled: false}, middle: {enabled: false}}
    };
    mapOptions.interaction = {
        ...(mapOptions.interaction || {}),
        dragNodes: true,
        dragView: true,
        zoomView: true
    };

    const network = new vis.Network(container, {nodes, edges}, mapOptions);

    // Fit the entire topology after device SVG images have loaded.
    network.once('stabilized', function () {
        network.fit({
            animation: false
        });
    });

    network.once('initRedraw', function () {
        network.fit({
            animation: false
        });
    });

    function portPosition(deviceId, interfaceName) {
        const drawing = deviceSvgs[deviceId];
        if (!drawing?.ports) return null;

        const port = findPhysicalPort(deviceId, interfaceName);

        if (!port) return null;

        const node = nodes.get(deviceId);
        if (!node) return null;

        const position = network.getPositions([deviceId])[deviceId];

        if (!position || !drawing.width || !drawing.height) {
            return null;
        }

        const width = Number(drawing.width);
        const height = Number(drawing.height);

        return {
            x: position.x - width / 2 + Number(port[1].x),
            y: position.y - height / 2 + Number(port[1].y)
        };
    }

    network.on('afterDrawing', function (ctx) {
        ctx.save();
        physicalLinks.forEach(edge => {
            const connection = connections.find(c => c.id === edge.id);
            if (!connection) return;

            const style = linkStyle(connection);
            ctx.strokeStyle = style.color;
            ctx.lineWidth = style.width;

            const from = portPosition(
                Number(connection.local_device_id),
                connection.local_interface
            );
            const to = portPosition(
                Number(connection.remote_device_id),
                connection.remote_interface
            );

            if (!from || !to) return;

            ctx.beginPath();
            ctx.moveTo(from.x, from.y);
            ctx.lineTo(to.x, to.y);
            ctx.stroke();
        });

        // Draw virtual-switch links at the real physical ports.
        virtualEdges.forEach(edge => {
            const fromId = edge.from;
            const toId = edge.to;
            const fromVirtual = typeof fromId === 'string' && fromId.startsWith('virtual:');
            const toVirtual = typeof toId === 'string' && toId.startsWith('virtual:');

            const virtualId = fromVirtual ? fromId : toId;
            const key = virtualId.replace(/^virtual:/, '');
            const links = groupsByPort.get(key) || [];

            let from = null;
            let to = null;

            if (toVirtual && links.length) {
                from = portPosition(fromId, links[0].local_interface);
            }

            if (fromVirtual) {
                const connection = links.find(c =>
                    Number(c.remote_device_id) === Number(toId)
                );
                if (connection) {
                    to = portPosition(toId, connection.remote_interface);
                }
            }

            const positions = network.getPositions([fromId, toId]);
            from = from || positions[fromId];
            to = to || positions[toId];

            if (!from || !to) return;

            ctx.strokeStyle = edge.color?.color || '#94a3b8';
            ctx.lineWidth = edge.width || 3;
            ctx.beginPath();
            ctx.moveTo(from.x, from.y);
            ctx.lineTo(to.x, to.y);
            ctx.stroke();
        });

        ctx.restore();
    });




    // VMNETRA_STATUS_BORDERS_V3
    const liveDeviceStatuses = new Map(
        devices.map(d => [Number(d.device_id), {
            status: d.status,
            activeAlerts: Number(d.active_alerts || 0)
        }])
    );

    function updateLiveDeviceStatuses(updatedDevices) {
        (updatedDevices || []).forEach(d => {
            liveDeviceStatuses.set(Number(d.device_id), {
                status: d.status,
                activeAlerts: Number(d.active_alerts || 0),
                metrics: d
            });
        });
        network.redraw();
    }

    // Draw alerts around the device body, excluding its label.
    network.on('afterDrawing', function(ctx) {
        const positions = network.getPositions();

        devices.forEach(device => {
            const id = Number(device.device_id);
            const info = liveDeviceStatuses.get(id);
            const drawing = deviceSvgs[id];
            const position = positions[id];

            if (!info || !drawing || !position) return;

            const online = info.status !== null
                && info.status !== undefined
                && Number(info.status) === 1;

            const color = !online
                ? '#ef4444'
                : info.activeAlerts > 0
                    ? '#eab308'
                    : null;

            if (!color) return;

            const width = Number(drawing.width);
            const height = Number(drawing.height);

            if (!(width > 0 && height > 0)) return;

            const x = position.x - width / 2;
            const y = position.y - height / 2;

            ctx.save();
            ctx.strokeStyle = color;
            ctx.lineWidth = 3;
            ctx.shadowColor = color;
            ctx.shadowBlur = 8;
            ctx.beginPath();

            if (Math.abs(width - height) < 5) {
                ctx.arc(
                    position.x,
                    position.y,
                    Math.max(width, height) / 2 + 2,
                    0,
                    Math.PI * 2
                );
            } else {
                ctx.roundRect(
                    x - 2,
                    y - 2,
                    width + 4,
                    height + 4,
                    5
                );
            }

            ctx.stroke();
            ctx.restore();
        });
    });

    // VMNETRA_LIVE_REFRESH_V1
    // Update existing ports and links without rebuilding the network.
    let topologyRefreshTimer = null;

    async function refreshTopologyLive() {
        let nextInterval = 30000;

        try {
            const refreshUrl = new URL(window.location.href);
            refreshUrl.searchParams.set('refresh', '1');

            const response = await fetch(refreshUrl.toString(), {
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'},
                cache: 'no-store'
            });

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const data = await response.json();

            const seconds = Number(data.refreshInterval);
            if ([10, 15, 30, 60, 120].includes(seconds)) {
                nextInterval = seconds * 1000;
            }

            if (data.refreshEnabled) {
                // Preserve the existing object reference.
                Object.keys(portStates).forEach(key => delete portStates[key]);
                Object.assign(portStates, data.portStates || {});


                // VMNETRA_LIVE_DISCOVERY_V1
                // Add newly discovered devices without moving existing nodes.
                const knownDevices = new Set(
                    devices.map(d => Number(d.device_id))
                );

                (data.devices || []).forEach(device => {
                    const id = Number(device.device_id);
                    if (!Number.isInteger(id) || knownDevices.has(id)) return;

                    const drawing = data.deviceSvgs?.[id];
                    if (drawing) deviceSvgs[id] = drawing;

                    const existing = nodes.getIds().filter(
                        nodeId => typeof nodeId === 'number'
                    ).length;

                    const position = network.getViewPosition();
                    const svgUrl = drawing?.svg
                        ? 'data:image/svg+xml;charset=utf-8,' +
                          encodeURIComponent(drawing.svg)
                        : null;

                    nodes.add({
                        id,
                        label: device.sysName || device.hostname,
                        title: device.hostname,
                        shape: svgUrl ? 'image' : 'box',
                        ...(svgUrl ? {
                            image: svgUrl,
                            size: 65,
                            shapeProperties: {useImageSize: true}
                        } : {}),
                        x: position.x + 200 + (existing % 4) * 120,
                        y: position.y + 150 + (existing % 5) * 90
                    });

                    devices.push(device);
                    knownDevices.add(id);
                    console.info('VMNETRA: New device:', device.sysName || id);
                });

                // Keep SVG metadata current for newly discovered ports.
                // Existing SVG models are not replaced while the map is open.

                const knownConnections = new Set(
                    connections.map(c => String(c.id))
                );

                (data.connections || []).forEach(connection => {
                    const id = String(connection.id);
                    if (knownConnections.has(id)) return;

                    const localId = Number(connection.local_device_id);
                    const remoteId = Number(connection.remote_device_id);

                    if (!nodes.get(localId) || !nodes.get(remoteId)) return;

                    // Existing virtual-switch groups require separate handling.
                    const localKey =
                        connection.local_device_id + ':' +
                        connection.local_interface;

                    // VMNETRA_VIRTUAL_LIVE_V1
                    if (groupsByPort.has(localKey) &&
                        virtualNodes.some(n => n.id === 'virtual:' + localKey)) {
                        const virtualId = 'virtual:' + localKey;
                        const childId = remoteId;
                        const downlinkId = 'downlink:' + localKey + ':' + childId;

                        connections.push(connection);
                        knownConnections.add(id);
                        groupsByPort.get(localKey).push(connection);
                        hiddenConnections.add(connection.id);

                        if (!virtualEdges.some(e => e.id === downlinkId)) {
                            const childStyle = linkStyle({
                                local_device_id: childId,
                                local_interface: connection.remote_interface,
                                remote_device_id: null,
                                remote_interface: null
                            });

                            virtualEdges.push({
                                id: downlinkId,
                                from: virtualId,
                                to: childId,
                                color: {
                                    color: childStyle.color,
                                    highlight: childStyle.color
                                },
                                width: childStyle.width,
                                smooth: false
                            });

                            console.info('VMNETRA: New virtual-switch downlink:', downlinkId);
                        }

                        return;
                    }

                    const style = linkStyle(connection);
                    const edge = {
                        id: connection.id,
                        from: localId,
                        to: remoteId,
                        title: (connection.local_interface || '?') + ' ↔ ' +
                               (connection.remote_interface || '?'),
                        color: {
                            color: style.color,
                            highlight: style.color
                        },
                        width: style.width,
                        smooth: false
                    };

                    connections.push(connection);
                    knownConnections.add(id);
                    normalEdges.push(edge);

                    if (!groupsByPort.has(localKey)) {
                        groupsByPort.set(localKey, []);
                    }
                    groupsByPort.get(localKey).push(connection);

                    const physical =
                        findPhysicalPort(localId, connection.local_interface) &&
                        findPhysicalPort(remoteId, connection.remote_interface);

                    if (physical) {
                        physicalLinks.push(edge);
                    } else {
                        edges.add(edge);
                    }

                    console.info('VMNETRA: New LLDP link:', connection.id);
                });


                // VMNETRA_AUTO_VIRTUAL_CREATE_V1
                // Detect new unmanaged switches using the original LLDP rules.
                const liveCandidates = [...groupsByPort.entries()]
                    .filter(([key, links]) =>
                        new Set(links.map(c => Number(c.remote_device_id))).size >= 3
                    )
                    .sort((a, b) => b[1].length - a[1].length);

                liveCandidates.forEach(([key, links]) => {
                    const virtualId = 'virtual:' + key;
                    if (nodes.get(virtualId)) return;

                    const parentId = Number(links[0].local_device_id);
                    const children = [...new Set(
                        links.map(c => Number(c.remote_device_id))
                    )].filter(id =>
                        id !== parentId &&
                        !assignedDevices.has(id) &&
                        !!nodes.get(id)
                    );

                    if (children.length < 3) return;

                    const childSet = new Set(children);
                    const mutualLinks = connections.filter(c =>
                        childSet.has(Number(c.local_device_id)) &&
                        childSet.has(Number(c.remote_device_id))
                    );

                    if (mutualLinks.length < 2) return;

                    const parent = nodes.get(parentId);
                    if (!parent) return;

                    const saved = virtualPositions[virtualId];
                    const labelBase = String(groupName || 'Network')
                        .trim()
                        .replace(/[^a-zA-Z0-9_-]+/g, '-')
                        .replace(/^-+|-+$/g, '') || 'Network';

                    const label = saved?.label ||
                        (labelBase + '-UNMAN_' + (virtualNodes.length + 1));

                    const parentPosition =
                        network.getPositions([parentId])[parentId] ||
                        {x: 0, y: 0};

                    const virtualNode = {
                        id: virtualId,
                        label,
                        title: 'Virtual switch — ' +
                               (links[0].local_interface || ''),
                        shape: 'box',
                        color: {
                            background: '#dddddd',
                            border: '#777777'
                        },
                        x: saved ? Number(saved.x) : parentPosition.x + 150,
                        y: saved ? Number(saved.y) : parentPosition.y + 80
                    };

                    nodes.add(virtualNode);
                    virtualNodes.push(virtualNode);

                    const uplinkStyle = linkStyle(links[0]);

                    virtualEdges.push({
                        id: 'uplink:' + key,
                        from: parentId,
                        to: virtualId,
                        title: links[0].local_interface || '?',
                        color: {
                            color: uplinkStyle.color,
                            highlight: uplinkStyle.color
                        },
                        width: uplinkStyle.width,
                        smooth: false
                    });

                    children.forEach(childId => {
                        assignedDevices.add(childId);

                        const childConnection = links.find(c =>
                            Number(c.remote_device_id) === childId
                        );

                        const childStyle = linkStyle({
                            local_device_id: childId,
                            local_interface: childConnection.remote_interface,
                            remote_device_id: null,
                            remote_interface: null
                        });

                        virtualEdges.push({
                            id: 'downlink:' + key + ':' + childId,
                            from: virtualId,
                            to: childId,
                            color: {
                                color: childStyle.color,
                                highlight: childStyle.color
                            },
                            width: childStyle.width,
                            smooth: false
                        });
                    });

                    const removedIds = [];

                    connections.forEach(c => {
                        const localId = Number(c.local_device_id);
                        const remoteId = Number(c.remote_device_id);

                        if (
                            (localId === parentId && childSet.has(remoteId)) ||
                            (remoteId === parentId && childSet.has(localId)) ||
                            (childSet.has(localId) && childSet.has(remoteId))
                        ) {
                            hiddenConnections.add(c.id);
                            removedIds.push(c.id);
                        }
                    });

                    const removedKeys = new Set(removedIds.map(String));

                    for (let i = normalEdges.length - 1; i >= 0; i--) {
                        if (removedKeys.has(String(normalEdges[i].id))) {
                            normalEdges.splice(i, 1);
                        }
                    }

                    for (let i = physicalLinks.length - 1; i >= 0; i--) {
                        if (removedKeys.has(String(physicalLinks[i].id))) {
                            physicalLinks.splice(i, 1);
                        }
                    }

                    removedIds.forEach(id => {
                        if (edges.get(id)) edges.remove(id);
                    });

                    console.info(
                        'VMNETRA: Auto-created virtual switch:',
                        virtualId, label
                    );
                });

                updateLiveDeviceStatuses(data.devices);

                // Refresh regular vis-network edges.
                const updates = [];

                normalEdges.forEach(edge => {
                    const connection = connections.find(c => c.id === edge.id);
                    if (!connection) return;

                    const style = linkStyle(connection);

                    edge.color = {
                        color: style.color,
                        highlight: style.color
                    };
                    edge.width = style.width;

                    if (edges.get(edge.id)) {
                        updates.push({
                            id: edge.id,
                            color: edge.color,
                            width: edge.width
                        });
                    }
                });

                if (updates.length) {
                    edges.update(updates);
                }

                // Refresh virtual-switch link colors.
                virtualEdges.forEach(edge => {
                    let connection = null;

                    if (String(edge.id).startsWith('uplink:')) {
                        const key = String(edge.id).slice(7);
                        connection = (groupsByPort.get(key) || [])[0];
                    } else if (String(edge.id).startsWith('downlink:')) {
                        const key = String(edge.from).replace(/^virtual:/, '');
                        const childId = Number(edge.to);

                        const original = (groupsByPort.get(key) || [])
                            .find(c => Number(c.remote_device_id) === childId);

                        if (original) {
                            connection = {
                                local_device_id: childId,
                                local_interface: original.remote_interface,
                                remote_device_id: null,
                                remote_interface: null
                            };
                        }
                    }

                    if (!connection) return;

                    const style = linkStyle(connection);
                    edge.color = {
                        color: style.color,
                        highlight: style.color
                    };
                    edge.width = style.width;
                });

                // Redraw physical links and animated port indicators.
                network.redraw();
            }
        } catch (error) {
            console.warn('VMNETRA topology refresh:', error);
        } finally {
            topologyRefreshTimer = window.setTimeout(
                refreshTopologyLive,
                nextInterval
            );
        }
    }

    if (Number(groupId) > 0) {
        topologyRefreshTimer = window.setTimeout(
            refreshTopologyLive,
            30000
        );
    }

    window.addEventListener('pagehide', function () {
        if (topologyRefreshTimer !== null) {
            window.clearTimeout(topologyRefreshTimer);
        }
    });

    // VMNETRA_PORT_PULSE
    // Animated indicators for active physical interfaces.
    let portPulsePhase = 0;

    function portSpeedColor(speed) {
        speed = Number(speed);
        if (!Number.isFinite(speed) || speed <= 0) return null;
        if (speed >= 10000000000) return '#1558b0';
        if (speed >= 2500000000) return '#38bdf8';
        if (speed >= 1000000000) return '#22c55e';
        if (speed >= 100000000) return '#eab308';
        return '#f97316';
    }

    network.on('afterDrawing', function (ctx) {
        const alpha = 0.35 + 0.65 * (0.5 + 0.5 * Math.sin(portPulsePhase));

        ctx.save();

        Object.entries(deviceSvgs).forEach(([deviceId, drawing]) => {
            if (!drawing?.ports) return;

            Object.entries(drawing.ports).forEach(([portName, port]) => {
                const state = getPortState(deviceId, port.interface || portName);
                if (!state) return;

                if (!['up', '1'].includes(String(state.status).trim().toLowerCase())) return;

                const color = portSpeedColor(state.speed);
                if (!color) return;

                const devicePosition = network.getPositions([Number(deviceId)])[Number(deviceId)];
                if (!devicePosition) return;

                const position = {
                    x: devicePosition.x - Number(drawing.width) / 2 + Number(port.x),
                    y: devicePosition.y - Number(drawing.height) / 2 + Number(port.y)
                };

                ctx.globalAlpha = alpha;
                ctx.strokeStyle = color;
                ctx.lineWidth = 3;
                ctx.shadowColor = color;
                ctx.shadowBlur = 9;

                ctx.strokeRect(position.x - 20, position.y - 14, 40, 28);
            });
        });

        ctx.restore();
    });


    const portPulseTimer = setInterval(() => {
        if (document.hidden) return;
        portPulsePhase += 0.22;
        network.redraw();
    }, 80);

    window.addEventListener('pagehide', () => clearInterval(portPulseTimer));


    // VMNETRA_LINK_DELETE_CLICK
    // Detect clicks on custom Canvas links and standard vis-network edges.
    network.on('click', async function (params) {
        if (!groupId || params.nodes.length) return;

        const pointer = params.pointer.canvas;
        let selected = null;
        let closest = 12 / network.getScale();

        physicalLinks.forEach(edge => {
            const connection = connections.find(c => c.id === edge.id);
            if (!connection) return;

            const a = portPosition(
                Number(connection.local_device_id),
                connection.local_interface
            );
            const b = portPosition(
                Number(connection.remote_device_id),
                connection.remote_interface
            );

            if (!a || !b) return;

            const dx = b.x - a.x;
            const dy = b.y - a.y;
            const lengthSquared = dx * dx + dy * dy;

            if (!lengthSquared) return;

            const t = Math.max(0, Math.min(1,
                ((pointer.x - a.x) * dx + (pointer.y - a.y) * dy) / lengthSquared
            ));

            const distance = Math.hypot(
                pointer.x - (a.x + t * dx),
                pointer.y - (a.y + t * dy)
            );

            if (distance < closest) {
                closest = distance;
                selected = connection;
            }
        });

        // Detect manually drawn virtual-switch links.
        if (!selected) {
            virtualEdges.forEach(edge => {
                const fromId = edge.from;
                const toId = edge.to;
                const fromVirtual = typeof fromId === 'string' &&
                    fromId.startsWith('virtual:');
                const toVirtual = typeof toId === 'string' &&
                    toId.startsWith('virtual:');

                const virtualId = fromVirtual ? fromId : toId;
                const key = virtualId.replace(/^virtual:/, '');
                const links = groupsByPort.get(key) || [];

                let a = null;
                let b = null;
                let candidates = [];

                if (toVirtual && links.length) {
                    a = portPosition(fromId, links[0].local_interface);
                    // Uplink represents all LLDP neighbors on this port.
                    candidates = links;
                }

                if (fromVirtual) {
                    candidates = links.filter(c =>
                        Number(c.remote_device_id) === Number(toId)
                    );
                    if (candidates.length === 1) {
                        b = portPosition(toId, candidates[0].remote_interface);
                    }
                }

                const positions = network.getPositions([fromId, toId]);
                a = a || positions[fromId];
                b = b || positions[toId];

                if (!a || !b) return;

                const dx = b.x - a.x;
                const dy = b.y - a.y;
                const lengthSquared = dx * dx + dy * dy;
                if (!lengthSquared) return;

                const t = Math.max(0, Math.min(1,
                    ((pointer.x - a.x) * dx +
                     (pointer.y - a.y) * dy) / lengthSquared
                ));

                const distance = Math.hypot(
                    pointer.x - (a.x + t * dx),
                    pointer.y - (a.y + t * dy)
                );

                if (distance < closest) {
                    closest = distance;
                    // Never select an arbitrary LLDP record.
                    selected = candidates.length === 1 ? candidates[0] : null;
                }
            });
        }

        if (!selected && params.edges.length) {
            selected = connections.find(c =>
                String(c.id) === String(params.edges[0])
            );
        }

        if (!selected || !selected.connection_key) return;

        const localDevice = devices.find(d =>
            Number(d.device_id) === Number(selected.local_device_id));
        const remoteDevice = devices.find(d =>
            Number(d.device_id) === Number(selected.remote_device_id));

        const localPort = getPortState(
            selected.local_device_id, selected.local_interface);
        const remotePort = getPortState(
            selected.remote_device_id, selected.remote_interface);

        const safe = value => String(value ?? '?').replace(/[&<>"']/g, ch =>
            ({'&':'&amp;', '<':'&lt;', '>':'&gt;',
              '"':'&quot;', "'":'&#39;'}[ch]));

        const speed = Number(localPort?.speed || remotePort?.speed || 0);
        const speedText = speed > 0
            ? (speed / 1000000000 >= 1
                ? (speed / 1000000000) + ' Gbps'
                : (speed / 1000000) + ' Mbps')
            : 'Unknown';

        const status = localPort?.status || remotePort?.status || 'unknown';

        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.65);display:flex;align-items:center;justify-content:center';

        overlay.innerHTML = `
          <div style="background:#1b2735;color:#e2e8f0;border:1px solid #405063;border-radius:12px;padding:24px;width:min(560px,94vw);box-shadow:0 15px 50px #0008">
            <h3 style="margin:0 0 20px;color:#fff">Link Information</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px">
              <div style="background:#263546;padding:14px;border-radius:8px">
                <strong>${safe(localDevice?.sysName || localDevice?.hostname)}</strong>
                <div style="margin-top:10px">Port: ${safe(selected.local_interface)}</div>
              </div>
              <div style="background:#263546;padding:14px;border-radius:8px">
                <strong>${safe(remoteDevice?.sysName || remoteDevice?.hostname)}</strong>
                <div style="margin-top:10px">Port: ${safe(selected.remote_interface)}</div>
              </div>
            </div>
            <table style="width:100%;color:#e2e8f0;margin-bottom:20px">
              <tr><td style="padding:9px;border-bottom:1px solid #405063">Speed</td><td>${safe(speedText)}</td></tr>
              <tr><td style="padding:9px;border-bottom:1px solid #405063">Status</td><td>${safe(status)}</td></tr>
              <tr><td style="padding:9px">Discovery</td><td>LLDP</td></tr>
            </table>
            <div style="display:flex;justify-content:flex-end;gap:10px">
              <button type="button" data-action="close" style="background:#475569;color:white;border:0;border-radius:6px;padding:10px 18px">Close</button>
              <button type="button" data-action="delete" style="background:#dc2626;color:white;border:0;border-radius:6px;padding:10px 18px">Delete Link</button>
            </div>
          </div>`;

        document.body.appendChild(overlay);

        overlay.querySelector('[data-action="close"]').onclick = () =>
            overlay.remove();

        overlay.addEventListener('click', e => {
            if (e.target === overlay) overlay.remove();
        });

        const deleteButton = overlay.querySelector('[data-action="delete"]');
        await new Promise(resolve => {
            deleteButton.onclick = () => resolve(true);
            overlay.querySelector('[data-action="close"]').addEventListener(
                'click', () => resolve(false));
            overlay.addEventListener('click', e => {
                if (e.target === overlay) resolve(false);
            });
        }).then(confirmed => {
            if (!confirmed) selected = null;
        });

        overlay.remove();
        if (!selected) return;
        if (!confirm('Are you sure you want to permanently delete this link?')) return;

        try {
            const response = await fetch(
                @json(url('/plugin/MikroTik/topology/connections/delete')),
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({
                        group_id: groupId,
                        connection_key: selected.connection_key
                    })
                }
            );

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            window.location.reload();
        } catch (error) {
            alert('Error deleting link: ' + error.message);
        }
    });


    // VMNETRA_PORT_CLICK_INFO
    // Use the existing physical-port coordinates without changing SVG models.
    network.on('click', function (params) {
        if (!groupId || !params.pointer?.canvas) return;

        const pointer = params.pointer.canvas;
        const tolerance = 14 / network.getScale();
        let selectedPort = null;
        let nearest = Infinity;

        Object.entries(deviceSvgs).forEach(([deviceId, drawing]) => {
            if (!drawing?.ports) return;

            const position = network.getPositions([Number(deviceId)])[Number(deviceId)];
            if (!position || !drawing.width || !drawing.height) return;

            Object.entries(drawing.ports).forEach(([name, port]) => {
                const x = position.x - Number(drawing.width) / 2 + Number(port.x);
                const y = position.y - Number(drawing.height) / 2 + Number(port.y);

                const distance = Math.hypot(pointer.x - x, pointer.y - y);

                if (distance < tolerance && distance < nearest) {
                    nearest = distance;
                    selectedPort = {
                        deviceId: Number(deviceId),
                        name: port.interface || name
                    };
                }
            });
        });

        if (!selectedPort) return;

        const device = devices.find(d =>
            Number(d.device_id) === selectedPort.deviceId
        );
        const state = getPortState(selectedPort.deviceId, selectedPort.name);

        const safe = value => String(value ?? 'No Data')
            .replace(/[&<>"']/g, ch =>
                ({'&':'&amp;', '<':'&lt;', '>':'&gt;',
                  '"':'&quot;', "'":'&#39;'}[ch]));

        const speed = Number(state?.speed || 0);
        const speedText = speed > 0
            ? speed >= 1000000000
                ? (speed / 1000000000) + ' Gbps'
                : (speed / 1000000) + ' Mbps'
            : 'Unknown';

        const status = String(state?.status || 'unknown');
        const isUp = ['up', '1'].includes(status.toLowerCase());

        const overlay = document.createElement('div');
        overlay.style.cssText =
            'position:fixed;inset:0;z-index:100001;background:rgba(0,0,0,.65);' +
            'display:flex;align-items:center;justify-content:center';

        overlay.innerHTML = `
          <div style="background:#1b2735;color:#e2e8f0;border:1px solid #405063;
                      border-radius:12px;padding:24px;width:min(480px,94vw);
                      box-shadow:0 15px 50px #0008">
            <h3 style="margin:0 0 20px;color:white">Port Information</h3>
            <div style="background:#263546;padding:15px;border-radius:8px;margin-bottom:16px">
              <strong>${safe(device?.sysName || device?.hostname)}</strong>
              <div style="margin-top:8px;font-size:17px">${safe(selectedPort.name)}</div>
            </div>
            <table style="width:100%;color:#e2e8f0;margin-bottom:20px">
              <tr><td style="padding:10px;border-bottom:1px solid #405063">Status</td>
                  <td style="color:${isUp ? '#22c55e' : '#ef4444'}">${safe(status.toUpperCase())}</td></tr>
              <tr><td style="padding:10px;border-bottom:1px solid #405063">Speed</td>
                  <td>${safe(speedText)}</td></tr>
              <tr><td style="padding:10px">Interface</td>
                  <td>${safe(state?.name || selectedPort.name)}</td></tr>
            </table>
            <div style="display:flex;justify-content:flex-end">
              ${state?.id ? `
              <a href="${@json(url('/device'))}/device=${selectedPort.deviceId}/tab=port/port=${Number(state.id)}/"
                 target="_blank" rel="noopener noreferrer"
                 style="background:#2563eb;color:white;border-radius:6px;
                        padding:10px 20px;text-decoration:none;margin-right:10px">
                Open Port
              </a>` : ''}
              <button type="button" data-action="close"
                      style="background:#475569;color:white;border:0;
                             border-radius:6px;padding:10px 20px">Close</button>
            </div>
          </div>`;

        document.body.appendChild(overlay);
        overlay.querySelector('[data-action="close"]').onclick = () => overlay.remove();
        overlay.addEventListener('click', e => {
            if (e.target === overlay) overlay.remove();
        });
    });


    // VMNETRA_DEVICE_INFO_V1
    // Open device details only when the click is not on a physical port.
    network.on('click', function (params) {
        if (!groupId || params.nodes.length !== 1 || !params.pointer?.canvas) return;

        const id = Number(params.nodes[0]);
        if (!Number.isFinite(id)) return;

        const device = devices.find(d => Number(d.device_id) === id);
        if (!device) return;

        const pointer = params.pointer.canvas;
        const tolerance = 14 / network.getScale();
        const drawing = deviceSvgs[id];
        const position = network.getPositions([id])[id];

        if (drawing?.ports && position && drawing.width && drawing.height) {
            const portClicked = Object.values(drawing.ports).some(port => {
                const x = position.x - Number(drawing.width) / 2 + Number(port.x);
                const y = position.y - Number(drawing.height) / 2 + Number(port.y);
                return Math.hypot(pointer.x - x, pointer.y - y) < tolerance;
            });

            if (portClicked) return;
        }

        const safe = value => String(value ?? 'Unknown')
            .replace(/[&<>"']/g, ch => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;',
                '"': '&quot;', "'": '&#39;'
            }[ch]));

        const live = liveDeviceStatuses.get(id);
        const status = live?.status;
        const online = status !== null && status !== undefined && Number(status) === 1;
        const unknown = status === null || status === undefined;
        const statusText = unknown ? 'Unknown' : online ? 'Online' : 'Offline';
        const statusColor = online ? '#22c55e' : '#ef4444';
        const alerts = Number(live?.activeAlerts || 0);

        const latest = live?.metrics || device;
        const percentage = value =>
            value === null || value === undefined || value === ''
                ? 'N/A'
                : Number(value).toFixed(1) + '%';

        const formatUptime = value => {
            if (value === null || value === undefined || value === '') return 'N/A';
            let seconds = Math.max(0, Number(value));
            if (!Number.isFinite(seconds)) return 'N/A';
            const days = Math.floor(seconds / 86400);
            const hours = Math.floor((seconds % 86400) / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            return `${days}d ${hours}h ${minutes}m`;
        };


        const name = device.sysName || device.hostname || 'Device ' + id;
        const ip = device.ip || device.hostname || 'Unknown';

        const overlay = document.createElement('div');
        overlay.style.cssText =
            'position:fixed;inset:0;z-index:100002;background:rgba(0,0,0,.65);' +
            'display:flex;align-items:center;justify-content:center';

        overlay.innerHTML = `
          <div style="background:#1b2735;color:#e2e8f0;border:1px solid #405063;
                      border-radius:12px;padding:24px;width:min(440px,94vw);
                      box-shadow:0 15px 50px #0008">
            <h3 style="margin:0 0 18px;color:white">Device Information</h3>
            <div style="background:#263546;padding:15px;border-radius:8px;margin-bottom:16px">
              <strong style="font-size:17px">${safe(name)}</strong>
              <div style="margin-top:7px;color:#94a3b8">${safe(ip)}</div>
            </div>
            <table style="width:100%;color:#e2e8f0;margin-bottom:20px">
              <tr><td style="padding:10px;border-bottom:1px solid #405063">Status</td>
                  <td style="color:${statusColor}">${statusText}</td></tr>
              <tr><td style="padding:10px;border-bottom:1px solid #405063">CPU Usage</td>
                  <td>${percentage(latest.cpu_usage)}</td></tr>
              <tr><td style="padding:10px;border-bottom:1px solid #405063">Memory Usage</td>
                  <td>${percentage(latest.memory_usage)}</td></tr>
              <tr><td style="padding:10px;border-bottom:1px solid #405063">Uptime</td>
                  <td>${formatUptime(latest.uptime_seconds)}</td></tr>
              <tr><td style="padding:10px">Active Alerts</td>
                  <td style="color:${alerts ? '#eab308' : '#e2e8f0'}">${alerts}</td></tr>
            </table>
            <div style="display:flex;justify-content:flex-end;gap:10px">
              <a href="${@json(url('/device'))}/device=${id}/"
                 target="_blank" rel="noopener noreferrer"
                 style="background:#2563eb;color:white;border-radius:6px;
                        padding:10px 18px;text-decoration:none">
                Open Device
              </a>
              <button type="button" data-action="close"
                      style="background:#475569;color:white;border:0;
                             border-radius:6px;padding:10px 18px">Close</button>
            </div>
          </div>`;

        document.body.appendChild(overlay);
        overlay.querySelector('[data-action="close"]').onclick = () => overlay.remove();
        overlay.addEventListener('click', e => {
            if (e.target === overlay) overlay.remove();
        });
    });

    network.on('dragEnd', async function (params) {
        if (!groupId || params.nodes.length !== 1) {
            return;
        }

        const deviceId = params.nodes[0];
        const position = network.getPositions([deviceId])[deviceId];

        if (!position) {
            return;
        }

        const isVirtual = typeof deviceId === 'string' && deviceId.startsWith('virtual:');
        const node = nodes.get(deviceId);

        try {
            const response = await fetch(isVirtual
                ? @json(url('/plugin/MikroTik/topology/virtual-positions'))
                : saveUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify(isVirtual ? {
                    group_id: groupId,
                    virtual_id: deviceId,
                    label: node.label,
                    x: position.x,
                    y: position.y
                } : {
                    group_id: groupId,
                    device_id: deviceId,
                    x: position.x,
                    y: position.y
                })
            });

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
        } catch (error) {
            console.error('MikroTik position save failed:', error);
            alert('Failed to save position: ' + error.message);
        }
    });
});
</script>
@endsection
