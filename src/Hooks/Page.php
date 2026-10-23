<?php

namespace LibreNMS\Plugins\MikroTik\Hooks;

use App\Plugins\Hooks\PageHook;
use App\Models\User;

class Page extends PageHook
{
    public string $view = "page";
    public function authorize(User $user): bool
    {
        return auth()->user()?->can('global-read') ?? false;
    }

    public function data(): array
    {
        return [
            'title' => 'MikroTik Network Maps',
            'version' => '0.1.0',
            'discoverySettings' => \Illuminate\Support\Facades\DB::table('mikrotik_settings')
                ->pluck('value', 'key')
                ->all(),
            'settings' => \App\Facades\PluginManager::getSettings('MikroTik'),
        ];
    }
}
