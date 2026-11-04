<?php

use App\Core\Extensions\Modules\ModuleManifest;
use App\Core\Extensions\Modules\ModuleStaffArea;

/**
 * APES Pet Care Clinic module package manifest (#285).
 *
 * Live prefix `/petcare` and route names `petcare.*` stay stable.
 */
$defaults = is_file(__DIR__.'/config/plugin-defaults.php')
    ? require __DIR__.'/config/plugin-defaults.php'
    : [];

return new ModuleManifest(
    slug: 'pet-care-clinic',
    name: 'APES Pet Care Clinic',
    description: 'Pet care records and clinical consultations.',
    routePrefix: '/petcare',
    routeNamePrefix: 'petcare.',
    hubRouteName: 'petcare.index',
    icon: 'heart-pulse',
    sortOrder: 30,
    staffArea: new ModuleStaffArea('petcare.index'),
    plugins: ['pet-profiles', 'consultations', 'tickets'],
    pluginDefaults: is_array($defaults) ? $defaults : [],
    hubView: 'sub-cores.show',
    packagePath: __DIR__,
);
