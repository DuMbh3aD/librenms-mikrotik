<div class="container-fluid">
    <h2><i class="fa fa-sitemap"></i> {{ $title }}</h2>
    <p class="text-muted">MikroTik Control Center — v{{ $version }}</p>
    <hr>

    <ul class="nav nav-tabs" role="tablist">
        <li><a href="#mt-general" data-toggle="tab">General</a></li>
        <li class="{{ request()->is("plugin/MikroTik") && session("discovery_saved") ? "active" : "" }}"><a href="#mt-discovery" data-toggle="tab">Discovery</a></li>
        <li><a href="#mt-api" data-toggle="tab">RouterOS API</a></li>
        <li><a href="#mt-logs" data-toggle="tab">Logs</a></li>
    </ul>

    <div class="tab-content" style="padding-top:20px">
        <div class="tab-pane {{ session("discovery_saved") ? "" : "active" }}" id="mt-general">
            <h3>General Settings</h3>
            <p>Plugin status: <span class="label label-success">Enabled</span></p>
            <p>Version: {{ $version }}</p>
        </div>

        <div class="tab-pane {{ session("discovery_saved") ? "active" : "" }}" id="mt-discovery">
            <h3>Discovery Settings</h3>

            <form method="POST" action="{{ url('plugin/MikroTik/settings') }}">
                @csrf

                <div class="checkbox">
                    <label>
                        <input type="checkbox" name="discovery_enabled" value="1"
                            {{ ($discoverySettings['discovery_enabled'] ?? '0') === '1' ? 'checked' : '' }}>
                        Enable automatic discovery
                    </label>
                </div>

                <div class="form-group">
                    <div class="checkbox">
    <label>
        <input type="checkbox" name="topology_refresh_enabled" value="1"
            {{ ($discoverySettings['topology_refresh_enabled'] ?? '1') === '1' ? 'checked' : '' }}>
        Enable topology auto-refresh
    </label>
</div>

<div class="form-group">
    <label for="topology_refresh_interval">Topology Refresh Interval</label>
    <select name="topology_refresh_interval" id="topology_refresh_interval"
            class="form-control" style="max-width:250px">
        @foreach([10, 15, 30, 60, 120] as $seconds)
            <option value="{{ $seconds }}"
                {{ (int) ($discoverySettings['topology_refresh_interval'] ?? 30) === $seconds ? 'selected' : '' }}>
                Every {{ $seconds }} seconds
            </option>
        @endforeach
    </select>
</div>

<label for="discovery_interval">Discovery Interval</label>
                    <select name="discovery_interval" id="discovery_interval" class="form-control" style="max-width:250px">
                        @foreach([1, 5, 10, 15, 30, 60] as $minutes)
                            <option value="{{ $minutes }}"
                                {{ (int) ($discoverySettings['discovery_interval'] ?? 5) === $minutes ? 'selected' : '' }}>
                                Every {{ $minutes }} minutes
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="checkbox">
                    <label>
                        <input type="checkbox" name="lldp_enabled" value="1"
                            {{ ($discoverySettings['lldp_enabled'] ?? '0') === '1' ? 'checked' : '' }}>
                        Enable LLDP discovery
                    </label>
                </div>

                <div class="checkbox">
                    <label>
                        <input type="checkbox" name="mndp_enabled" value="1"
                            {{ ($discoverySettings['mndp_enabled'] ?? '0') === '1' ? 'checked' : '' }}
                            disabled>
                        Enable MNDP discovery (coming soon)
                    </label>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-save"></i> Save Settings
                </button>
                
            </form>
        </div>

        <div class="tab-pane" id="mt-api">
            <h3>RouterOS API</h3>
            <p>Manage RouterOS API connections and credentials.</p>
            <label><input type="checkbox" disabled> Enable RouterOS API</label>
        </div>

        <div class="tab-pane" id="mt-logs">
            <h3>Plugin Logs</h3>
            <p class="text-muted">Discovery and topology activity will appear here.</p>
        </div>
    </div>

    <hr>
    <p class="text-muted">
        MikroTik plugin settings.
    </p>
</div>
