<?php

namespace App\Services;

use App\Core\Accounts\User;
use App\Core\Extensions\Plugins\PluginEnablement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Plugins\Cases\CasesArea;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Consultations\Models\PetCareConsultation;
use Plugins\PetProfiles\Models\PetProfile;
use Plugins\PetProfiles\PetProfilesArea;
use Plugins\Recruitment\Models\RecruitmentApplication;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\TicketsArea;

/**
 * Per-type record lists for a managed user's profile hub (#519).
 */
final class UserRecordsSummary
{
    private const PER_PAGE = 10;

    /** @var list<string> */
    private const TICKET_MODULES = ['apes-cic', 'shelter-rescue', 'pet-care-clinic'];

    /** @var list<string> */
    private const CASE_MODULES = ['apes-cic', 'shelter-rescue'];

    /** @var list<string> */
    private const PET_MODULES = ['shelter-rescue', 'pet-care-clinic'];

    public function __construct(
        private readonly PluginEnablement $plugins,
    ) {}

    /**
     * @return array{
     *     tickets: ?LengthAwarePaginator,
     *     pets: ?LengthAwarePaginator,
     *     cases: ?LengthAwarePaginator,
     *     consultations: ?LengthAwarePaginator,
     *     applications: ?LengthAwarePaginator,
     * }
     */
    public function forViewer(User $subject, User $viewer, int $page = 1): array
    {
        return [
            'tickets' => $this->tickets($subject, $viewer, $page),
            'pets' => $this->pets($subject, $viewer, $page),
            'cases' => $this->cases($subject, $viewer, $page),
            'consultations' => $this->consultations($subject, $viewer, $page),
            'applications' => $this->applications($subject, $viewer, $page),
        ];
    }

    /**
     * @return list<array{key: string, label: string, url: string}>
     */
    public function modulesInUse(User $subject, User $viewer): array
    {
        $links = [];

        foreach ([
            'tickets' => __('admin.users.hub.records.tickets'),
            'pets' => __('admin.users.hub.records.pets'),
            'cases' => __('admin.users.hub.records.cases'),
            'consultations' => __('admin.users.hub.records.consultations'),
            'applications' => __('admin.users.hub.records.applications'),
        ] as $key => $label) {
            if (! $this->hasRecordsOfType($key, $subject, $viewer)) {
                continue;
            }

            $links[] = [
                'key' => $key,
                'label' => $label,
                'url' => '?tab=records#hub-records-'.$key,
            ];
        }

        return $links;
    }

    private function hasRecordsOfType(string $type, User $subject, User $viewer): bool
    {
        return match ($type) {
            'tickets' => $this->ticketQuery($subject, $viewer)?->exists() ?? false,
            'pets' => $this->petQuery($subject, $viewer)?->exists() ?? false,
            'cases' => $this->caseQuery($subject, $viewer)?->exists() ?? false,
            'consultations' => $this->consultationQuery($subject, $viewer)?->exists() ?? false,
            'applications' => $this->applicationQuery($subject, $viewer)?->exists() ?? false,
            default => false,
        };
    }

    /**
     * @return Builder<SupportTicket>|null
     */
    private function ticketQuery(User $subject, User $viewer): ?Builder
    {
        $modules = $this->enabledModulesForViewer(
            self::TICKET_MODULES,
            'tickets',
            $viewer,
            static fn (string $module): string => TicketsArea::forModule($module)->permission('view-all'),
        );

        if ($modules === []) {
            return null;
        }

        return SupportTicket::query()
            ->where('user_id', $subject->id)
            ->whereIn('sub_core_key', $modules);
    }

    /**
     * @return Builder<PetProfile>|null
     */
    private function petQuery(User $subject, User $viewer): ?Builder
    {
        $domains = [];

        foreach ([
            PetProfile::DOMAIN_SHELTER => 'shelter-rescue',
            PetProfile::DOMAIN_PETCARE => 'pet-care-clinic',
        ] as $domain => $module) {
            if (! $this->plugins->isEnabled($module, 'pet-profiles')) {
                continue;
            }

            if (! $viewer->can(PetProfilesArea::forModule($module)->permission('view-all'))) {
                continue;
            }

            $domains[] = $domain;
        }

        if ($domains === []) {
            return null;
        }

        return PetProfile::query()
            ->where('user_id', $subject->id)
            ->whereIn('service_domain', $domains);
    }

    /**
     * @return Builder<ShelterCase>|null
     */
    private function caseQuery(User $subject, User $viewer): ?Builder
    {
        $modules = $this->enabledModulesForViewer(
            self::CASE_MODULES,
            'cases',
            $viewer,
            static fn (string $module): string => CasesArea::forModule($module)->permission('view-all'),
        );

        if ($modules === []) {
            return null;
        }

        return ShelterCase::query()
            ->where('user_id', $subject->id)
            ->whereIn('sub_core_key', $modules);
    }

    /**
     * @return Builder<PetCareConsultation>|null
     */
    private function consultationQuery(User $subject, User $viewer): ?Builder
    {
        if (! $this->plugins->isEnabled('pet-care-clinic', 'consultations')) {
            return null;
        }

        if (! $viewer->can('pet-care-clinic.consultations.view-all')) {
            return null;
        }

        return PetCareConsultation::query()->where('user_id', $subject->id);
    }

    /**
     * @return Builder<RecruitmentApplication>|null
     */
    private function applicationQuery(User $subject, User $viewer): ?Builder
    {
        if (! $this->plugins->isEnabled('apes-cic', 'recruitment')) {
            return null;
        }

        if (! $viewer->can(RecruitmentApplication::PERMISSION_PREFIX.'view-all')
            && ! $viewer->can(RecruitmentApplication::PERMISSION_PREFIX.'review-applications')) {
            return null;
        }

        return RecruitmentApplication::query()->where('user_id', $subject->id);
    }

    private function tickets(User $subject, User $viewer, int $page): ?LengthAwarePaginator
    {
        $query = $this->ticketQuery($subject, $viewer);
        if ($query === null) {
            return null;
        }

        return $this->paginateMapped(
            $query->latest('id'),
            $page,
            static function (SupportTicket $ticket): array {
                $area = TicketsArea::forModule((string) $ticket->sub_core_key);

                return [
                    'type' => 'ticket',
                    'reference' => '#'.$ticket->id,
                    'status' => (string) $ticket->status,
                    'date' => $ticket->created_at,
                    'label' => (string) $ticket->subject,
                    'url' => route($area->showRouteName(), $ticket),
                    'module' => (string) $ticket->sub_core_key,
                ];
            },
        );
    }

    private function pets(User $subject, User $viewer, int $page): ?LengthAwarePaginator
    {
        $query = $this->petQuery($subject, $viewer);
        if ($query === null) {
            return null;
        }

        return $this->paginateMapped(
            $query->latest('id'),
            $page,
            static function (PetProfile $pet): array {
                $module = $pet->service_domain === PetProfile::DOMAIN_SHELTER
                    ? 'shelter-rescue'
                    : 'pet-care-clinic';
                $area = PetProfilesArea::forModule($module);

                return [
                    'type' => 'pet',
                    'reference' => '#'.$pet->id,
                    'status' => (string) ($pet->neutering_status ?? '—'),
                    'date' => $pet->created_at,
                    'label' => (string) ($pet->name ?: __('admin.users.hub.records.unnamed_pet')),
                    'url' => route($area->showRouteName(), $pet),
                    'module' => $module,
                ];
            },
        );
    }

    private function cases(User $subject, User $viewer, int $page): ?LengthAwarePaginator
    {
        $query = $this->caseQuery($subject, $viewer);
        if ($query === null) {
            return null;
        }

        return $this->paginateMapped(
            $query->latest('id'),
            $page,
            static function (ShelterCase $case): array {
                $area = CasesArea::forModule((string) $case->sub_core_key);

                return [
                    'type' => 'case',
                    'reference' => '#'.$case->id,
                    'status' => (string) $case->status,
                    'date' => $case->created_at,
                    'label' => (string) $case->title,
                    'url' => route($area->showRouteName(), $case),
                    'module' => (string) $case->sub_core_key,
                ];
            },
        );
    }

    private function consultations(User $subject, User $viewer, int $page): ?LengthAwarePaginator
    {
        $query = $this->consultationQuery($subject, $viewer);
        if ($query === null) {
            return null;
        }

        return $this->paginateMapped(
            $query->latest('id'),
            $page,
            static function (PetCareConsultation $consultation): array {
                return [
                    'type' => 'consultation',
                    'reference' => '#'.$consultation->id,
                    'status' => (string) $consultation->status,
                    'date' => $consultation->created_at,
                    'label' => (string) ($consultation->subject ?: __('admin.users.hub.records.consultation')),
                    'url' => route('petcare.consultations.show', $consultation),
                    'module' => 'pet-care-clinic',
                ];
            },
        );
    }

    private function applications(User $subject, User $viewer, int $page): ?LengthAwarePaginator
    {
        $query = $this->applicationQuery($subject, $viewer);
        if ($query === null) {
            return null;
        }

        return $this->paginateMapped(
            $query->with('recruitmentRole')->latest('id'),
            $page,
            static function (RecruitmentApplication $application): array {
                return [
                    'type' => 'application',
                    'reference' => '#'.$application->id,
                    'status' => (string) $application->status,
                    'date' => $application->created_at ?? $application->submitted_at,
                    'label' => (string) ($application->recruitmentRole?->title
                        ?? __('admin.users.hub.records.application')),
                    'url' => route('apes-cic.recruitment.applications.show', $application),
                    'module' => 'apes-cic',
                ];
            },
        );
    }

    /**
     * @param  list<string>  $modules
     * @param  callable(string): string  $viewAllPermission
     * @return list<string>
     */
    private function enabledModulesForViewer(
        array $modules,
        string $plugin,
        User $viewer,
        callable $viewAllPermission,
    ): array {
        $enabled = [];

        foreach ($modules as $module) {
            if (! $this->plugins->isEnabled($module, $plugin)) {
                continue;
            }

            if (! $viewer->can($viewAllPermission($module))) {
                continue;
            }

            $enabled[] = $module;
        }

        return $enabled;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  callable(TModel): array<string, mixed>  $mapper
     */
    private function paginateMapped(Builder $query, int $page, callable $mapper): LengthAwarePaginator
    {
        $paginator = $query->paginate(self::PER_PAGE, ['*'], 'records_page', max(1, $page));
        $paginator->setCollection(
            $paginator->getCollection()->map($mapper)->values(),
        );

        return $paginator;
    }
}
