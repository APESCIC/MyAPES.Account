<?php

namespace Plugins\Recruitment;

/**
 * Recruitment ability strings grouped by the access matrix in the plugin README (#343).
 */
final class RecruitmentAbilities
{
    public const VIEW_OWN = 'apes-cic.recruitment.view-own';

    public const CREATE = 'apes-cic.recruitment.create';

    public const VIEW_ALL = 'apes-cic.recruitment.view-all';

    public const UPDATE = 'apes-cic.recruitment.update';

    public const DELETE = 'apes-cic.recruitment.delete';

    public const REVIEW_APPLICATIONS = 'apes-cic.recruitment.review-applications';

    /** Browse Open roles, apply, and track My applications. */
    public const BROWSE = [
        self::VIEW_OWN,
    ];

    /** Manage Roles and Applications (HR, Admin, Super Admin). */
    public const MANAGE = [
        self::VIEW_ALL,
        self::CREATE,
        self::UPDATE,
        self::DELETE,
        self::REVIEW_APPLICATIONS,
    ];

    /** Any ability that opens the Applications manage list. */
    public const APPLICATIONS_MANAGE = [
        self::VIEW_ALL,
        self::REVIEW_APPLICATIONS,
    ];
}
