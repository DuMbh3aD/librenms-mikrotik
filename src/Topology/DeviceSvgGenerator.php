<?php

namespace LibreNMS\Plugins\MikroTik\Topology;

class DeviceSvgGenerator
{
    public function renderLayout(string $model, array $ports, int $width, int $height, string $bodyShape = "rounded", float $labelX = 16, float $labelY = 23): string
    {
        $safeModel = htmlspecialchars($model, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $svg = [
            '<svg xmlns="http://www.w3.org/2000/svg"'
                . ' width="' . $width . '" height="' . $height . '"'
                . ' viewBox="0 0 ' . $width . ' ' . $height . '">',
            $bodyShape === 'circle'
                ? '<ellipse cx="' . ($width / 2) . '" cy="' . ($height / 2)
                    . '" rx="' . (($width - 4) / 2)
                    . '" ry="' . (($height - 4) / 2)
                    . '" fill="#26343f" stroke="#657785" stroke-width="3"/>'
                : '<rect x="2" y="2" width="' . ($width - 4)
                    . '" height="' . ($height - 4)
                    . '" rx="' . ($bodyShape === 'rectangle' ? 0 : 9)
                    . '" fill="#26343f" stroke="#657785" stroke-width="3"/>',
            '<text x="' . $labelX . '" y="' . $labelY
                . '" font-family="Arial" font-size="14"'
                . ' font-weight="bold" fill="white">' . $safeModel . '</text>',
        ];

        foreach ($ports as $name => $port) {
            $x = (float) $port['x'];
            $y = (float) $port['y'];

            $safeName = htmlspecialchars(
                (string) $name,
                ENT_QUOTES | ENT_XML1,
                'UTF-8'
            );

            $svg[] = '<g>'
                . '<rect x="' . ($x - 20) . '" y="' . ($y - 14)
                . '" width="40" height="28" rx="3"'
                . ' fill="#101c27" stroke="#a3b4c2"/>'
                . '<text x="' . $x . '" y="' . ($y + 4)
                . '" text-anchor="middle" font-family="Arial"'
                . ' font-size="9" fill="white">'
                . $safeName . '</text>'
                . '</g>';
        }

        $svg[] = '</svg>';

        return implode("\n", $svg);
    }

    public function generate(string $model, array $ports): array
    {
        $physical = [];

        foreach ($ports as $port) {
            $name = is_array($port)
                ? ($port['ifName'] ?? '')
                : ($port->ifName ?? '');

            if (! preg_match('/^(ether|sfp|qsfp)/i', $name)) {
                continue;
            }

            // Preserve the physical interface name regardless of its description.
            if (! preg_match('/^((?:ether\d+|sfp-sfpplus\d+|sfp\d+|qsfp(?:plus|28)?\d+))(?=$|[-_])/i', $name, $match)) {
                continue;
            }

            $physical[$match[1]] = $name;
        }

        $count = count($physical);
        $columns = min(12, max(1, (int) ceil($count / 2)));
        $rows = max(1, (int) ceil($count / $columns));

        $width = max(360, 35 + $columns * 52);
        $height = 58 + $rows * 42;

        $safeModel = htmlspecialchars($model, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $svg = [
            '<svg xmlns="http://www.w3.org/2000/svg"'
            . ' width="' . $width . '" height="' . $height . '"'
            . ' viewBox="0 0 ' . $width . ' ' . $height . '">',
            '<rect x="2" y="2" width="' . ($width - 4)
            . '" height="' . ($height - 4)
            . '" rx="9" fill="#26343f" stroke="#657785" stroke-width="3"/>',
            '<text x="16" y="23" font-family="Arial" font-size="14"'
            . ' font-weight="bold" fill="white">' . $safeModel . '</text>',
        ];

        $anchors = [];
        $index = 0;

        foreach ($physical as $physicalName => $interfaceName) {
            if (stripos($model, 'CRS326-24G-2S+') !== false
                && preg_match('/^ether(\\d+)$/i', $physicalName, $portMatch)) {
                $number = (int) $portMatch[1];
                $column = intdiv($number - 1, 2);
                $row = ($number % 2 === 0) ? 0 : 1;
            } else {
                $column = $index % $columns;
                $row = intdiv($index, $columns);
            }

            $x = 16 + $column * 52;
            $y = 35 + $row * 42;

            $safeName = htmlspecialchars(
                $physicalName,
                ENT_QUOTES | ENT_XML1,
                'UTF-8'
            );

            $svg[] = '<g>'
                . '<rect x="' . $x . '" y="' . $y
                . '" width="40" height="28" rx="3"'
                . ' fill="#101c27" stroke="#a3b4c2"/>'
                . '<text x="' . ($x + 20) . '" y="' . ($y + 18)
                . '" text-anchor="middle" font-family="Arial"'
                . ' font-size="9" fill="white">'
                . $safeName . '</text>'
                . '</g>';

            $anchors[$physicalName] = [
                'x' => $x + 20,
                'y' => $y + 14,
                'interface' => $interfaceName,
            ];

            $index++;
        }

        $svg[] = '</svg>';

        return [
            'svg' => implode("\n", $svg),
            'width' => $width,
            'height' => $height,
            'ports' => $anchors,
        ];
    }
}
