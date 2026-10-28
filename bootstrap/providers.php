<?php

use App\Core\Providers\CoreAccessServiceProvider;
use App\Core\Providers\CoreAdminServiceProvider;
use App\Core\Providers\CoreAuthServiceProvider;
use App\Core\Providers\CoreMailServiceProvider;
use App\Core\Providers\CoreServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\ExtensionRegistryServiceProvider;
use Modules\ApesCic\ApesCicServiceProvider;
use Modules\PetCareClinic\PetCareClinicServiceProvider;
use Modules\ShelterRescue\ShelterRescueServiceProvider;
use Plugins\Cases\CasesServiceProvider;
use Plugins\Consultations\ConsultationsServiceProvider;
use Plugins\PetProfiles\PetProfilesServiceProvider;
use Plugins\Recruitment\RecruitmentServiceProvider;
use Plugins\Tickets\TicketsServiceProvider;

return [
    CoreServiceProvider::class,
    CoreAuthServiceProvider::class,
    CoreAccessServiceProvider::class,
    CoreAdminServiceProvider::class,
    CoreMailServiceProvider::class,
    ExtensionRegistryServiceProvider::class,
    AppServiceProvider::class,
    ApesCicServiceProvider::class,
    PetCareClinicServiceProvider::class,
    ShelterRescueServiceProvider::class,
    TicketsServiceProvider::class,
    CasesServiceProvider::class,
    RecruitmentServiceProvider::class,
    ConsultationsServiceProvider::class,
    PetProfilesServiceProvider::class,
];
