# LibreNMS MikroTik Plugin

A community-developed plugin for LibreNMS providing MikroTik network
discovery, topology visualization, and physical link monitoring.

> **Status:** Early development (0.1.0).
> Installation and compatibility testing are still in progress.

## Features

- LLDP-based network topology discovery
- MNDP discovery support
- Network topology maps organized by LibreNMS Device Groups
- Physical interface and link visualization
- Device status, metrics, and active alert information
- MikroTik device model identification and images
- Configurable discovery and topology refresh settings
- Adaptive link monitoring
- Interactive topology positioning and virtual devices

Some features are experimental and may change.

## Requirements

- A compatible LibreNMS installation with plugin support
- PHP 8.2 or newer
- Composer
- Network devices configured for the required discovery protocols
- Appropriate LibreNMS permissions

Additional compatibility requirements will be documented after testing.

## Installation

A reproducible Composer installation procedure is being tested.

Do not use this development version in production without validating
compatibility and maintaining a backup.

## Permissions

Viewing plugin pages requires LibreNMS `global-read` permission.

Changing plugin settings and topology information requires
`global-admin` permission.

## Development

Development takes place in GitHub. Changes should be tested before
being merged into the stable branch.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).

## Disclaimer

This is an independent community project and is not an official
MikroTik or LibreNMS product.
