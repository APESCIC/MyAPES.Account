@use('Plugins\Recruitment\RecruitmentAbilities')
@inject('recruitmentModuleSettings', 'App\Services\ModuleSettingsService')
<nav class="admin-nav" aria-label="{{ __('recruitment::public._navigation.blade.recruitment_sections') }}">
    @if($recruitmentModuleSettings->recruitmentPublicBoardEnabled())
        <a
            href="{{ route('recruitment.index') }}"
            @if(request()->routeIs('recruitment.index', 'recruitment.show')) aria-current="page" @endif
        >
            <i data-lucide="briefcase" aria-hidden="true"></i>
            <span>{{ __('terms.open_roles') }}</span>
        </a>
    @endif
    @can(RecruitmentAbilities::VIEW_OWN)
        <a
            href="{{ route('recruitment.applications.index') }}"
            @if(request()->routeIs('recruitment.applications.*')) aria-current="page" @endif
        >
            <i data-lucide="circle-user-round" aria-hidden="true"></i>
            <span>{{ __('terms.my_applications') }}</span>
        </a>
    @endcan
    @can(RecruitmentAbilities::VIEW_ALL)
        <a
            href="{{ route('apes-cic.recruitment.index') }}"
            @if(request()->routeIs('apes-cic.recruitment.*') && ! request()->routeIs('apes-cic.recruitment.applications.*')) aria-current="page" @endif
        >
            <i data-lucide="clipboard-list" aria-hidden="true"></i>
            <span>{{ __('terms.recruitment_roles') }}</span>
        </a>
    @endcan
    @canany(RecruitmentAbilities::APPLICATIONS_MANAGE)
        <a
            href="{{ route('apes-cic.recruitment.applications.index') }}"
            @if(request()->routeIs('apes-cic.recruitment.applications.*')) aria-current="page" @endif
        >
            <i data-lucide="inbox" aria-hidden="true"></i>
            <span>{{ __('terms.applications') }}</span>
        </a>
    @endcanany
</nav>
