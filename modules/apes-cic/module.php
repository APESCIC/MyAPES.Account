<?php

use App\Core\Extensions\Modules\ModuleManifest;
use App\Core\Extensions\Modules\ModuleStaffArea;

/**
 * APES CIC module package manifest (#284).
 *
 * Live prefix `/apes-cic` and route names `apes-cic.*` stay stable.
 * Plugins are composed via enablement; controllers stay under App\Http until Waves 5–7.
 */
$defaults = is_file(__DIR__.'/config/plugin-defaults.php')
    ? require __DIR__.'/config/plugin-defaults.php'
    : [];

return new ModuleManifest(
    slug: 'apes-cic',
    name: 'APES CIC',
    description: 'Member support and organisation services.',
    routePrefix: '/apes-cic',
    routeNamePrefix: 'apes-cic.',
    hubRouteName: 'apes-cic.index',
    icon: 'building-2',
    sortOrder: 10,
    staffArea: new ModuleStaffArea('apes-cic.index'),
    plugins: ['tickets', 'cases', 'recruitment'],
    pluginDefaults: is_array($defaults) ? $defaults : [],
    hubView: 'sub-cores.show',
    packagePath: __DIR__,
    version: '1.0.0',
);
