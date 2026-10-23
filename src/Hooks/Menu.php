<?php

namespace LibreNMS\Plugins\MikroTik\Hooks;

use App\Models\User;
use App\Plugins\Hooks\MenuEntryHook;

class Menu extends MenuEntryHook
{
    public string $view = 'menu';

    public function authorize(User $user): bool
    {
        return auth()->user()?->can('global-read') ?? false;
    }
}
