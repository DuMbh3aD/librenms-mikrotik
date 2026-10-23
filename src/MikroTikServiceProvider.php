<?php

namespace LibreNMS\Plugins\MikroTik;

use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;

class MikroTikServiceProvider extends ServiceProvider
{
    public function boot(PluginManagerInterface $manager): void
    {

        $this->app->booted(function () {
            if (! $this->app->runningInConsole()) {
                return;
            }

            $schedule = $this->app->make(
                \Illuminate\Console\Scheduling\Schedule::class
            );

            $schedule->call(function () {
                $settings = \Illuminate\Support\Facades\DB::table('mikrotik_settings')
                    ->pluck('value', 'key');

                if (($settings['discovery_enabled'] ?? '0') !== '1'
                    || ($settings['lldp_enabled'] ?? '0') !== '1') {
                    return;
                }

                $interval = (int) ($settings['discovery_interval'] ?? 5);

                if (! in_array($interval, [1, 5, 10, 15, 30, 60], true)) {
                    return;
                }

                if (((int) now()->format('i')) % $interval !== 0) {
                    return;
                }

                (new \LibreNMS\Plugins\MikroTik\Discovery\LldpImporter())->import();
            })
                ->name('mikrotik:lldp-import')
                ->everyMinute()
                ->withoutOverlapping(10);

            $schedule->call(function () {
                (new \LibreNMS\Plugins\MikroTik\Monitoring\AdaptiveLinkPoller())->run();
            })
                ->name('mikrotik:adaptive-polling')
                ->everyFifteenSeconds()
                ->withoutOverlapping(2);

            $schedule->call(function () {
                (new \LibreNMS\Plugins\MikroTik\Topology\DeviceModelSync())->sync();
            })
                ->name('mikrotik:model-sync')
                ->hourly()
                ->withoutOverlapping(30);
        });

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $pluginName = 'MikroTik';

        $manager->publishHook(
            $pluginName,
            SinglePageHook::class,
            Hooks\Page::class
        );

        $manager->publishHook(
            $pluginName,
            \LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook::class,
            Hooks\Menu::class
        );

        $this->loadViewsFrom(
            __DIR__ . '/../resources/views',
            $pluginName
        );
    }
}
