<?php

namespace Tests\Feature\Push;

use App\Core\Push\PushNotificationTypeKeys;
use App\Core\Push\PushNotificationTypeRegistry;
use App\Core\Push\PushRoleDefault;
use App\Core\Push\PushRoleResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PushNotificationTypeCatalogueTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, PushRoleDefault>, 3: bool, 4: bool}>
     */
    public static function epicRows(): array
    {
        $on = PushRoleDefault::On;
        $opt = PushRoleDefault::OptIn;
        $na = PushRoleDefault::NotApplicable;
        $never = PushRoleDefault::Never;

        return [
            PushNotificationTypeKeys::TICKET_CREATED => [
                PushNotificationTypeKeys::TICKET_CREATED,
                'push.types.ticket_created',
                [
                    'staff' => $on,
                    'volunteer' => $opt,
                    'student' => $opt,
                    'service_user' => $na,
                    'admin' => $opt,
                ],
                false,
                true,
            ],
            PushNotificationTypeKeys::TICKET_CLIENT_REPLY => [
                PushNotificationTypeKeys::TICKET_CLIENT_REPLY,
                'push.types.ticket_client_reply',
                [
                    'staff' => $on,
                    'volunteer' => $on,
                    'student' => $on,
                    'service_user' => $na,
                    'admin' => $na,
                ],
                false,
                false,
            ],
            PushNotificationTypeKeys::TICKET_STAFF_REPLY => [
                PushNotificationTypeKeys::TICKET_STAFF_REPLY,
                'push.types.ticket_staff_reply',
                [
                    'staff' => $na,
                    'volunteer' => $na,
                    'student' => $na,
                    'service_user' => $on,
                    'admin' => $na,
                ],
                false,
                false,
            ],
            PushNotificationTypeKeys::TICKET_ASSIGNED => [
                PushNotificationTypeKeys::TICKET_ASSIGNED,
                'push.types.ticket_assigned',
                [
                    'staff' => $on,
                    'volunteer' => $on,
                    'student' => $on,
                    'service_user' => $na,
                    'admin' => $na,
                ],
                false,
                false,
            ],
            PushNotificationTypeKeys::TICKET_INTERNAL_NOTE => [
                PushNotificationTypeKeys::TICKET_INTERNAL_NOTE,
                'push.types.ticket_internal_note',
                [
                    'staff' => $opt,
                    'volunteer' => $opt,
                    'student' => $opt,
                    'service_user' => $never,
                    'admin' => $na,
                ],
                false,
                false,
            ],
            PushNotificationTypeKeys::TICKET_STATUS_CHANGED => [
                PushNotificationTypeKeys::TICKET_STATUS_CHANGED,
                'push.types.ticket_status_changed',
                [
                    'staff' => $na,
                    'volunteer' => $na,
                    'student' => $na,
                    'service_user' => $on,
                    'admin' => $na,
                ],
                false,
                false,
            ],
            PushNotificationTypeKeys::CASE_UPDATE_SHARED => [
                PushNotificationTypeKeys::CASE_UPDATE_SHARED,
                'push.types.case_update_shared',
                [
                    'staff' => $on,
                    'volunteer' => $on,
                    'student' => $on,
                    'service_user' => $on,
                    'admin' => $na,
                ],
                false,
                false,
            ],
            PushNotificationTypeKeys::CONSULTATION_UPDATE => [
                PushNotificationTypeKeys::CONSULTATION_UPDATE,
                'push.types.consultation_update',
                [
                    'staff' => $on,
                    'volunteer' => $na,
                    'student' => $na,
                    'service_user' => $on,
                    'admin' => $na,
                ],
                false,
                false,
            ],
            PushNotificationTypeKeys::RECRUITMENT_APPLICATION_UPDATE => [
                PushNotificationTypeKeys::RECRUITMENT_APPLICATION_UPDATE,
                'push.types.recruitment_application_update',
                [
                    'staff' => $opt,
                    'volunteer' => $on,
                    'student' => $on,
                    'service_user' => $on,
                    'admin' => $na,
                ],
                false,
                false,
            ],
            PushNotificationTypeKeys::ACCOUNT_SECURITY_CHANGE => [
                PushNotificationTypeKeys::ACCOUNT_SECURITY_CHANGE,
                'push.types.account_security_change',
                [
                    'staff' => $on,
                    'volunteer' => $on,
                    'student' => $on,
                    'service_user' => $on,
                    'admin' => $on,
                ],
                true,
                false,
            ],
            PushNotificationTypeKeys::TICKET_EMAIL_REPLY_FAILED => [
                PushNotificationTypeKeys::TICKET_EMAIL_REPLY_FAILED,
                'push.types.ticket_email_reply_failed',
                [
                    'staff' => $on,
                    'volunteer' => $on,
                    'student' => $on,
                    'service_user' => $on,
                    'admin' => $na,
                ],
                false,
                false,
            ],
            PushNotificationTypeKeys::MAIL_IMPORT_ATTENTION => [
                PushNotificationTypeKeys::MAIL_IMPORT_ATTENTION,
                'push.types.mail_import_attention',
                [
                    'staff' => $na,
                    'volunteer' => $na,
                    'student' => $na,
                    'service_user' => $na,
                    'admin' => $on,
                ],
                false,
                false,
            ],
            PushNotificationTypeKeys::STAFF_MESSAGE => [
                PushNotificationTypeKeys::STAFF_MESSAGE,
                'push.types.staff_message',
                [
                    'staff' => $na,
                    'volunteer' => $opt,
                    'student' => $opt,
                    'service_user' => $opt,
                    'admin' => $na,
                ],
                false,
                false,
            ],
        ];
    }

    #[DataProvider('epicRows')]
    public function test_every_epic_row_is_registered_with_approved_defaults(
        string $key,
        string $labelKey,
        array $roleDefaults,
        bool $securityLocked,
        bool $divisionScoped,
    ): void {
        $registry = app(PushNotificationTypeRegistry::class);
        $type = $registry->get($key);

        $this->assertSame($labelKey, $type->labelKey);
        $this->assertSame($securityLocked, $type->securityLocked);
        $this->assertSame($divisionScoped, $type->divisionScoped);

        foreach (PushRoleResolver::catalogueRoles() as $role) {
            $this->assertArrayHasKey($role, $roleDefaults, "{$key} missing role {$role}");
            $this->assertSame(
                $roleDefaults[$role],
                $type->defaultForRole($role),
                "{$key} default for {$role}",
            );
        }
    }

    public function test_catalogue_lists_every_epic_type_once(): void
    {
        $keys = array_keys(app(PushNotificationTypeRegistry::class)->all());

        $this->assertSame(PushNotificationTypeKeys::catalogueKeys(), array_values($keys));
    }
}