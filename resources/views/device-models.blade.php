@extends('layouts.librenmsv1')

@section('title', 'MikroTik Device Models')

@section('content')
<div class="container-fluid">
    <h3>MikroTik Device Models</h3>
    <p class="text-muted">
        Automatically discovered device models and their physical layouts.
        Manually verified models will be protected from automatic changes.
    </p>

    <div class="panel panel-default">
        <div class="panel-heading">
            <strong>Device Model Catalog</strong>
            <span class="badge">{{ $models->count() }}</span>
        </div>

        <div class="panel-body table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Model</th>
                        <th>Manufacturer</th>
                        <th>Device Image</th>
                        
                        <th>Status</th>
                        
                        <th>Preview</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($models as $model)
                        <tr>
                            <td><strong>{{ $model->model }}</strong></td>
                            <td>{{ $model->manufacturer }}</td>
                            <td>
    @if($model->image_url)
        <img src="{{ url("/plugin/MikroTik/device-models/{$model->id}/cached-image", [], false) }}"
             alt="{{ $model->model }}"
             style="width:130px;height:65px;object-fit:contain">
    @elseif($model->svg)
        <img src="data:image/svg+xml;base64,{{ base64_encode($model->svg) }}"
             alt="{{ $model->model }}"
             style="width:130px;height:65px;object-fit:contain">
    @else
        <span class="text-muted">No image</span>
    @endif
</td>
                            
                            <td>
                                @if($model->verified)
                                    <span class="label label-success">Verified</span>
                                @elseif($model->manually_edited)
                                    <span class="label label-info">Manually edited</span>
                                @else
                                    <span class="label label-warning">Unverified</span>
                                @endif
                            </td>
                            
                            <td>
    @if($model->svg)
        <img src="data:image/svg+xml;base64,{{ base64_encode($model->svg) }}"
             alt="{{ $model->model }}"
             style="max-width:180px;max-height:75px">
    @else
        <span class="text-muted">No preview</span>
    @endif
</td>
<td>
                                @php
    $images = json_decode($model->image_candidates ?: '[]', true);
    $images = is_array($images) ? $images : [];
@endphp

<button type="button" class="btn btn-primary btn-sm"
        data-toggle="collapse"
        data-target="#model-images-{{ $model->id }}">
    <i class="fa fa-pencil"></i> Edit
</button>
                            </td>
                    </tr>

                    <tr id="model-images-{{ $model->id }}" class="collapse">
                            <td colspan="6">
                                <h4>Edit device model: {{ $model->model }}</h4>

                        <div class="mt-layout-editor"
                             data-model-id="{{ $model->id }}"
                             data-width="{{ $model->width }}"
                             data-height="{{ $model->height }}"
                             data-body-shape="{{ $model->body_shape ?: 'rounded' }}"
                           data-label-x="{{ $model->label_x ?? 16 }}"
                           data-label-y="{{ $model->label_y ?? 23 }}"
                             data-ports='@json(json_decode($model->ports ?: '{}', true))'
                             data-save-url="{{ url('/plugin/MikroTik/device-models/' . $model->id . '/layout', [], false) }}">

                            <h4>Layout Editor</h4>
                            <p class="text-muted">
                                Drag ports to adjust their positions.
                                Changes are saved only when you click Save Layout.
                            </p>

                            <div class="mt-body-controls" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin:12px 0">
    <label style="margin:0">Shape:
        <select class="mt-body-shape form-control input-sm" style="display:inline-block;width:150px">
            <option value="rounded">Rounded rectangle</option>
            <option value="rectangle">Rectangle</option>
            <option value="circle">Circle</option>
        </select>
    </label>
    <label style="margin:0">Width:
        <input type="number" class="mt-body-width form-control input-sm"
               min="100" max="3000" step="10"
               style="display:inline-block;width:90px"> px
    </label>
    <label style="margin:0">Height:
        <input type="number" class="mt-body-height form-control input-sm"
               min="50" max="2000" step="10"
               style="display:inline-block;width:90px"> px
    </label>
</div>

<div class="mt-layout-canvas"
                                 style="width:100%;height:420px;border:1px solid #536575;border-radius:8px;background:#202b35">
                            </div>

                            <div style="margin-top:12px">
                                
<div class="mt-layout-align-tools" style="margin:12px 0;display:flex;flex-wrap:wrap;gap:6px;align-items:center">
    <strong style="margin-right:8px">Align ports:</strong>
    <label style="margin:0 6px 0 0">
        Horizontal spacing:
        <input type="number" class="mt-spacing-x form-control input-sm"
               value="52" min="28" max="300" step="1"
               style="display:inline-block;width:75px">
        px
    </label>
    <label style="margin:0 10px 0 0">
        Vertical spacing:
        <input type="number" class="mt-spacing-y form-control input-sm"
               value="42" min="28" max="300" step="1"
               style="display:inline-block;width:75px">
        px
    </label>
    
    
    
    
    
    
    <button type="button" class="btn btn-primary btn-sm mt-apply-spacing">
        Apply Spacing
    </button>
    <span class="text-muted" style="margin-left:8px">Select ports with Cmd/Ctrl + click</span>
</div>

<button type="button" class="btn btn-success mt-layout-save">
                                    <i class="fa fa-save"></i> Save Layout
                                </button>
                                <button type="button" class="btn btn-default mt-layout-reset">
                                    Reset unsaved changes
                                </button>
                                <span class="mt-layout-status" style="margin-left:12px"></span>
                            </div>
                        </div>

                        <hr>


                        <h5>MikroTik product</h5>
                        @php
                            $productMatch = $productMatches[$model->model] ?? null;
                            $productCandidates = $productMatch['candidates'] ?? [];
                        @endphp
                        @if(count($productCandidates) > 1)
                            <form method="POST"
                                  action="{{ route('mikrotik.device-models.product.save', $model->id) }}">
                                @csrf
                                <select name="source_url" class="form-control input-sm" required>
                                    <option value="">Select MikroTik product</option>
                                    @foreach($productCandidates as $product)
                                        <option value="{{ $product['url'] }}"
                                            {{ $model->source_url === $product['url'] ? 'selected' : '' }}>
                                            {{ $product['code'] }}
                                        </option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-primary btn-xs"
                                        style="margin-top:5px">
                                    Save product
                                </button>
                            </form>
                        @endif

                        <hr>
                        <h5>Select device image</h5>

                                <form method="POST"
                                      action="{{ route('mikrotik.device-models.image.save', $model->id) }}">
                                    @csrf

                                    <div style="display:flex;flex-wrap:wrap;gap:15px">
                                        @foreach($images as $image)
                                            <label style="width:250px;border:1px solid #ccc;padding:10px;cursor:pointer">
                                                <input type="radio" name="image_url"
                                                       value="{{ $image['url'] }}"
                                                       {{ $model->image_url === $image['url'] ? 'checked' : '' }}
                                                       required>

                                                <img src="{{ $image['url'] }}" loading="lazy" decoding="async"
                                                     alt="Device image {{ $image['id'] }}"
                                                     style="display:block;width:100%;height:150px;object-fit:contain;margin-top:10px">

                                                <small>Image {{ $image['id'] }}</small>
                                            </label>
                                        @endforeach
                                    </div>

                                    <button type="submit" class="btn btn-success"
                                            style="margin-top:15px">
                                        <i class="fa fa-save"></i> Save selected image
                                    </button>
                                </form>
                            </td>
                        </tr>
                @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted">
                                No device models registered yet.
                                Models will appear here after automatic discovery is enabled.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="{{ asset('js/vis-network.min.js') }}"></script>
<script id="mt-layout-network-init">
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = @json(csrf_token());

    document.querySelectorAll('.mt-layout-editor').forEach(function (editor) {
        const canvas = editor.querySelector('.mt-layout-canvas');
        const saveButton = editor.querySelector('.mt-layout-save');
        const resetButton = editor.querySelector('.mt-layout-reset');
        const status = editor.querySelector('.mt-layout-status');

        const width = Number(editor.dataset.width);
        const height = Number(editor.dataset.height);
        const originalPorts = JSON.parse(editor.dataset.ports || '{}');
        const portNames = Object.keys(originalPorts);

        if (!width || !height || !portNames.length) {
            status.textContent = 'No ports available for editing.';
            return;
        }

        const modelName = editor.closest('tr').querySelector('h4')
            .textContent.replace('Edit device model: ', '').trim();

        const labelX = Number(editor.dataset.labelX);
        const labelY = Number(editor.dataset.labelY);
        const labelId = '__device_label__';

        function labelWidth() {
            const measure = document.createElement('canvas').getContext('2d');
            measure.font = 'bold 14px Arial';
            return measure.measureText(modelName).width;
        }

        const labelTextWidth = labelWidth();

        const nodes = new vis.DataSet([{
            id: labelId,
            label: modelName,
            x: labelX - width / 2 + labelTextWidth / 2,
            y: labelY - height / 2 - 5,
            shape: 'text',
            font: {color: '#ffffff', size: 14, face: 'Arial', bold: true},
            fixed: false,
            physics: false
        }].concat(portNames.map(function (name) {
            const port = originalPorts[name];
            return {
                id: name,
                label: name,
                x: Number(port.x) - width / 2,
                y: Number(port.y) - height / 2,
                shape: 'box',
                widthConstraint: {minimum: 40, maximum: 40},
                heightConstraint: {minimum: 28},
                color: {background: '#101c27', border: '#a3b4c2'},
                font: {color: '#ffffff', size: 9, face: 'Arial'},
                borderWidth: 1,
                shapeProperties: {borderRadius: 3},
                margin: 5,
                fixed: false,
                physics: false
            };
        })));

        const network = new vis.Network(canvas, {
            nodes: nodes,
            edges: new vis.DataSet([])
        }, {
            physics: false,
            interaction: {
                dragNodes: true,
                dragView: true,
                zoomView: true,
                multiselect: true,
                selectable: true
            },
            nodes: {
                shadow: false
            }
        });

        // Draw the device body behind interactive port nodes.
        network.on('beforeDrawing', function (ctx) {

            const w = Number(bodyWidthInput.value);
            const h = Number(bodyHeightInput.value);
            const shape = bodyShapeInput.value;

            ctx.save();
            ctx.fillStyle = '#26343f';
            ctx.strokeStyle = '#657785';
            ctx.lineWidth = 3;

            ctx.beginPath();
            if (shape === 'circle') {
                ctx.ellipse(0, 0, (w - 4) / 2, (h - 4) / 2, 0, 0, Math.PI * 2);
            } else {
                const x = -w / 2 + 2;
                const y = -h / 2 + 2;
                const rw = w - 4;
                const rh = h - 4;
                const r = shape === 'rectangle' ? 0 : 9;

                ctx.moveTo(x + r, y);
                ctx.lineTo(x + rw - r, y);
                ctx.quadraticCurveTo(x + rw, y, x + rw, y + r);
                ctx.lineTo(x + rw, y + rh - r);
                ctx.quadraticCurveTo(x + rw, y + rh, x + rw - r, y + rh);
                ctx.lineTo(x + r, y + rh);
                ctx.quadraticCurveTo(x, y + rh, x, y + rh - r);
                ctx.lineTo(x, y + r);
                ctx.quadraticCurveTo(x, y, x + r, y);
            }
            ctx.closePath();
            ctx.fill();
            ctx.stroke();
            ctx.restore();
        });

        // Live device body editing; persistence will be added separately.
        const bodyWidthInput = editor.querySelector('.mt-body-width');
        const bodyHeightInput = editor.querySelector('.mt-body-height');
        const bodyShapeInput = editor.querySelector('.mt-body-shape');

        bodyWidthInput.value = width;
        bodyHeightInput.value = height;
        bodyShapeInput.value = editor.dataset.bodyShape === 'circle'
            ? 'circle'
            : editor.dataset.bodyShape === 'rectangle'
                ? 'rectangle'
                : 'rounded';

        function updateBodyPreview() {
            const bodyWidth = Number(bodyWidthInput.value);
            const bodyHeight = Number(bodyHeightInput.value);
            const shape = bodyShapeInput.value;

            if (!Number.isFinite(bodyWidth) || bodyWidth < 100 || bodyWidth > 3000 ||
                !Number.isFinite(bodyHeight) || bodyHeight < 50 || bodyHeight > 2000) {
                status.textContent = 'Invalid body dimensions.';
                return;
            }

            network.redraw();
            fitEditor();

            status.textContent = 'Body changed — preview only, not saved yet.';
        }

        [bodyWidthInput, bodyHeightInput, bodyShapeInput].forEach(function (input) {
            input.addEventListener('change', updateBodyPreview);
        });

        // Apply the saved body shape and dimensions when opening the editor.
        updateBodyPreview();

        let initialized = false;

        function fitEditor() {
            const w = Number(bodyWidthInput.value) || width;
            const h = Number(bodyHeightInput.value) || height;
            const scale = Math.min(
                (canvas.clientWidth - 50) / w,
                (canvas.clientHeight - 50) / h,
                2
            );

            if (Number.isFinite(scale) && scale > 0) {
                network.moveTo({
                    position: {x: 0, y: 0},
                    scale: scale,
                    animation: false
                });
            }
        }

        const collapse = editor.closest('.collapse');

        if (collapse) {
            $(collapse).on('shown.bs.collapse', function () {
                if (!initialized) {
                    initialized = true;
                    network.redraw();
                }
                fitEditor();
            });
        } else {
            fitEditor();
        }

        // Apply uniform spacing to Ethernet ports, preserving their physical pairs.
        editor.querySelector('.mt-apply-spacing').addEventListener('click', function () {
            const spacingX = Number(editor.querySelector('.mt-spacing-x').value);
            const spacingY = Number(editor.querySelector('.mt-spacing-y').value);

            if (!Number.isFinite(spacingX) || spacingX < 56 || spacingX > 300 ||
                !Number.isFinite(spacingY) || spacingY < 36 || spacingY > 300) {
                status.textContent = 'Minimum spacing: 56 px horizontal, 36 px vertical.';
                return;
            }

            const ethernet = portNames
                .filter(name => /^ether\d+$/i.test(name))
                .sort((a, b) => Number(a.slice(5)) - Number(b.slice(5)));

            if (ethernet.length < 2) {
                status.textContent = 'Not enough Ethernet ports to arrange.';
                return;
            }

            const positions = network.getPositions(ethernet);
            const first = positions[ethernet[0]];

            // Keep the first column in place, including its vertical centre.
            const firstColumnX = first.x;
            const centreY = first.y - spacingY / 2;
            const updates = [];

            ethernet.forEach(function (name) {
                const number = Number(name.slice(5));
                const column = Math.floor((number - 1) / 2);
                const topRow = number % 2 === 0;

                updates.push({
                    id: name,
                    x: firstColumnX + column * spacingX,
                    y: centreY + (topRow ? -spacingY / 2 : spacingY / 2)
                });
            });

            const marginX = 28;
            const marginTop = 35;
            const marginBottom = 20;

            if (updates.some(port =>
                port.x < -width / 2 + marginX ||
                port.x > width / 2 - marginX ||
                port.y < -height / 2 + marginTop ||
                port.y > height / 2 - marginBottom
            )) {
                status.textContent = 'Ports do not fit. Reduce spacing or move ether1 left.';
                return;
            }

            nodes.update(updates);
            status.textContent = 'Spacing applied — click Save Layout to save.';
        });

        // Snap dragged ports to alignment guides and configured spacing.
        network.on('dragEnd', function (params) {
            const dragged = params.nodes.filter(id => portNames.includes(id));

            if (!dragged.length) {
                return;
            }

            const spacingX = Math.max(56, Math.min(300,
                Number(editor.querySelector('.mt-spacing-x').value) || 52));
            const spacingY = Math.max(36, Math.min(300,
                Number(editor.querySelector('.mt-spacing-y').value) || 42));

            const positions = network.getPositions(portNames);
            const updates = [];
            const threshold = 12;
            const margin = 25;

            dragged.forEach(function (id) {
                let x = positions[id].x;
                let y = positions[id].y;
                let bestX = threshold;
                let bestY = threshold;

                portNames.forEach(function (otherId) {
                    if (otherId === id || dragged.includes(otherId)) {
                        return;
                    }

                    const other = positions[otherId];

                    [other.x, other.x - spacingX, other.x + spacingX].forEach(function (candidate) {
                        const distance = Math.abs(x - candidate);
                        if (distance < bestX) {
                            bestX = distance;
                            x = candidate;
                        }
                    });

                    [other.y, other.y - spacingY, other.y + spacingY].forEach(function (candidate) {
                        const distance = Math.abs(y - candidate);
                        if (distance < bestY) {
                            bestY = distance;
                            y = candidate;
                        }
                    });
                });

                const currentWidth = Number(bodyWidthInput.value);
                const currentHeight = Number(bodyHeightInput.value);

                x = Math.max(-currentWidth / 2 + margin,
                    Math.min(currentWidth / 2 - margin, x));
                y = Math.max(-currentHeight / 2 + margin,
                    Math.min(currentHeight / 2 - margin, y));

                updates.push({id: id, x: x, y: y});
            });

            nodes.update(updates);
            status.textContent = 'Unsaved changes — ports snapped to guides';
        });

        resetButton.addEventListener('click', function () {
            nodes.update(portNames.map(function (name) {
                const port = originalPorts[name];

                return {
                    id: name,
                    x: Number(port.x) - width / 2,
                    y: Number(port.y) - height / 2
                };
            }));

            nodes.update({
                id: labelId,
                x: labelX - width / 2 + labelTextWidth / 2,
                y: labelY - height / 2 - 5
            });

            fitEditor();
            status.textContent = 'Changes reset';
        });

        saveButton.addEventListener('click', async function () {
            const currentWidth = Number(bodyWidthInput.value);
            const currentHeight = Number(bodyHeightInput.value);

            if (!Number.isInteger(currentWidth) || currentWidth < 100 || currentWidth > 3000 ||
                !Number.isInteger(currentHeight) || currentHeight < 50 || currentHeight > 2000) {
                status.textContent = 'Invalid body dimensions.';
                return;
            }

            const positions = network.getPositions(portNames);
            const labelPosition = network.getPositions([labelId])[labelId];
            const currentLabelX = labelPosition.x + currentWidth / 2 - labelTextWidth / 2;
            const currentLabelY = labelPosition.y + currentHeight / 2 + 5;

            if (!Number.isFinite(currentLabelX) ||
                !Number.isFinite(currentLabelY) ||
                currentLabelX < 0 || currentLabelX > currentWidth ||
                currentLabelY < 0 || currentLabelY > currentHeight) {
                status.textContent = 'Device name is outside the model.';
                return;
            }

            const ports = {};

            for (const name of portNames) {
                const x = positions[name].x + currentWidth / 2;
                const y = positions[name].y + currentHeight / 2;

                if (x < 0 || x > currentWidth || y < 0 || y > currentHeight) {
                    status.textContent = 'Port outside device: ' + name;
                    return;
                }

                ports[name] = {
                    x: Math.round(x * 100) / 100,
                    y: Math.round(y * 100) / 100
                };
            }

            saveButton.disabled = true;
            status.textContent = 'Saving...';

            try {
                const response = await fetch(editor.dataset.saveUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({
                        width: currentWidth,
                        height: currentHeight,
                        body_shape: bodyShapeInput.value,
                        label_x: Math.round(currentLabelX * 100) / 100,
                        label_y: Math.round(currentLabelY * 100) / 100,
                        ports: ports
                    })
                });

                if (!response.ok) {
                    const result = await response.json().catch(() => ({}));
                    const details = result.error
                        || result.message
                        || (result.errors ? JSON.stringify(result.errors) : null);
                    throw new Error(details || ('HTTP ' + response.status));
                }

                portNames.forEach(function (name) {
                    originalPorts[name].x = ports[name].x;
                    originalPorts[name].y = ports[name].y;
                });

                editor.dataset.labelX = currentLabelX;
                editor.dataset.labelY = currentLabelY;
                status.textContent = 'Layout saved successfully';
            } catch (error) {
                status.textContent = 'Save failed: ' + error.message;
            } finally {
                saveButton.disabled = false;
            }
        });
    });
});
</script>

@endsection
