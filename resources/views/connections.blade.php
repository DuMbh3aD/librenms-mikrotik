@extends('layouts.librenmsv1')

@section('title', 'MikroTik - Connections')

@section('content')
<div class="container-fluid">
    <h2><i class="fa fa-code-fork"></i> Connections</h2>
    <p class="text-muted">Connections discovered and recorded in LibreNMS</p>

    <p>
        <a href="{{ url('plugin/MikroTik') }}" class="btn btn-default">
            <i class="fa fa-arrow-left"></i> MikroTik Control Center
        </a>
    </p>

    <form method="GET" action="{{ url('plugin/MikroTik/connections') }}" class="well">
        <div class="row">
            <div class="col-md-3">
                <label for="protocol">Protocol</label>
                <select name="protocol" id="protocol" class="form-control">
                    <option value="">All Protocols</option>
                    @foreach($protocols as $item)
                        <option value="{{ $item }}" {{ $protocol === $item ? 'selected' : '' }}>
                            {{ strtoupper($item) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-5">
                <label for="search">Search</label>
                <input
                    type="text"
                    name="search"
                    id="search"
                    class="form-control"
                    value="{{ $search }}"
                    placeholder="Device, IP/hostname or port">
            </div>

            <div class="col-md-2">
                <label>&nbsp;</label>
                <div class="checkbox">
                    <label>
                        <input type="checkbox" name="unresolved" value="1"
                            {{ $unresolved ? 'checked' : '' }}>
                        Unknown Endpoint
                    </label>
                </div>
            </div>

            <div class="col-md-2">
                <label>&nbsp;</label>
                <div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-filter"></i> Filter
                    </button>
                    <a href="{{ url('plugin/MikroTik/connections') }}"
                       class="btn btn-default" title="Clear Filters">
                        <i class="fa fa-times"></i>
                    </a>
                </div>
            </div>
        </div>
    </form>

    <p class="text-muted">
        Records on Current Page: {{ $connections->count() }}
        · Total Results: {{ $connections->total() }}
    </p>

    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Protocol</th>
                    <th>Device A</th>
                    <th>Port A</th>
                    <th>Device B</th>
                    <th>Port B</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($connections as $connection)
                    <tr>
                        <td>{{ $connection->id }}</td>
                        <td>{{ strtoupper($connection->protocol ?? 'UNKNOWN') }}</td>
                        <td>{{ $connection->local_device ?? 'Unknown' }}</td>
                        <td>{{ $connection->local_port ?? 'Unknown' }}</td>
                        <td>{{ $connection->remote_device ?? $connection->remote_hostname ?? 'Unknown' }}</td>
                        <td>{{ $connection->remote_ifname ?? $connection->remote_port ?? 'Unknown' }}</td>
                        <td>
                            @if($connection->status === "resolved")
                                <span class="label label-success">Resolved</span>
                            @elseif($connection->status === "unresolved")
                                <span class="label label-warning">Unresolved</span>
                            @else
                                <span class="label label-default">{{ $connection->status }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center">
                            No connections match the selected filters.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $connections->links() }}
</div>
@endsection
