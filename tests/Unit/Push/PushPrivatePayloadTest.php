<?php

namespace Tests\Unit\Push;

use App\Core\Push\PushDeepLinkCatalogue;
use App\Core\Push\PushNotificationTypeKeys;
use App\Core\Push\PushPayloadBuilder;
use App\Core\Push\PushTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Push\Concerns\AssertsPrivatePushPayload;

/**
 * Private push JSON for every registered notification type (#799).
 */
class PushPrivatePayloadTest extends TestCase
{
    use AssertsPrivatePushPayload;

    /**
     * @return array<string, array{0: string, 1: PushTarget}>
     */
    public static function registeredTypes(): array
    {
        $service = 'Shelter and Rescue';

        return [
            PushNotificationTypeKeys::TICKET_CREATED => [
                PushNotificationTypeKeys::TICKET_CREATED,
                new PushTarget('shelter.tickets.show', ['ticket' => 123], '123', $service),
            ],
            PushNotificationTypeKeys::TICKET_CLIENT_REPLY => [
                PushNotificationTypeKeys::TICKET_CLIENT_REPLY,
                new PushTarget('apes-cic.tickets.show', ['ticket' => 456], '456', 'APES CIC'),
            ],
            PushNotificationTypeKeys::TICKET_STAFF_REPLY => [
                PushNotificationTypeKeys::TICKET_STAFF_REPLY,
                new PushTarget('petcare.tickets.show', ['ticket' => 789], '789', 'APES Pet Care Clinic'),
            ],
            PushNotificationTypeKeys::TICKET_ASSIGNED => [
                PushNotificationTypeKeys::TICKET_ASSIGNED,
                new PushTarget('shelter.tickets.show', ['ticket' => 12], '12', $service),
            ],
            PushNotificationTypeKeys::TICKET_INTERNAL_NOTE => [
                PushNotificationTypeKeys::TICKET_INTERNAL_NOTE,
                new PushTarget('apes-cic.tickets.show', ['ticket' => 34], '34', 'APES CIC'),
            ],
            PushNotificationTypeKeys::TICKET_STATUS_CHANGED => [
                PushNotificationTypeKeys::TICKET_STATUS_CHANGED,
                new PushTarget('petcare.tickets.show', ['ticket' => 56], '56', 'APES Pet Care Clinic'),
            ],
            PushNotificationTypeKeys::CASE_UPDATE_SHARED => [
                PushNotificationTypeKeys::CASE_UPDATE_SHARED,
                new PushTarget('shelter.cases.show', ['case' => 21], '21', $service),
            ],
            PushNotificationTypeKeys::CONSULTATION_UPDATE => [
                PushNotificationTypeKeys::CONSULTATION_UPDATE,
                new PushTarget('petcare.consultations.show', ['consultation' => 88], '88', 'APES Pet Care Clinic'),
            ],
            PushNotificationTypeKeys::RECRUITMENT_APPLICATION_UPDATE => [
                PushNotificationTypeKeys::RECRUITMENT_APPLICATION_UPDATE,
                new PushTarget('recruitment.applications.show', ['recruitmentApplication' => 9], '9', 'APES CIC'),
            ],
            PushNotificationTypeKeys::ACCOUNT_SECURITY_CHANGE => [
                PushNotificationTypeKeys::ACCOUNT_SECURITY_CHANGE,
                new PushTarget('profile.edit'),
            ],
            PushNotificationTypeKeys::TICKET_EMAIL_REPLY_FAILED => [
                PushNotificationTypeKeys::TICKET_EMAIL_REPLY_FAILED,
                new PushTarget('apes-cic.tickets.show', ['ticket' => 65], '65', 'APES CIC'),
            ],
            PushNotificationTypeKeys::MAIL_IMPORT_ATTENTION => [
                PushNotificationTypeKeys::MAIL_IMPORT_ATTENTION,
                new PushTarget('admin.emails.inbound.show', ['inboundEmail' => 3], '3'),
            ],
            PushNotificationTypeKeys::STAFF_MESSAGE => [
                PushNotificationTypeKeys::STAFF_MESSAGE,
                new PushTarget('dashboard', [], '77'),
            ],
        ];
    }

    #[DataProvider('registeredTypes')]
    public function test_every_type_builds_a_private_payload_under_the_byte_limit(string $typeKey, PushTarget $target): void
    {
        $payload = app(PushPayloadBuilder::class)->payloadArray($typeKey, $target);

        $this->assertPrivatePushPayload($payload);

        $reference = $target->referenceForBody();
        if ($reference !== '') {
            $this->assertStringContainsString('#'.$reference, $payload['body'] ?? '');
        }

        $this->assertStringNotContainsString('Jane Doe', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_account_security_payload_omits_record_reference(): void
    {
        $payload = app(PushPayloadBuilder::class)->payloadArray(
            PushNotificationTypeKeys::ACCOUNT_SECURITY_CHANGE,
            new PushTarget('profile.edit'),
        );

        $this->assertPrivatePushPayload($payload);
        $this->assertSame('Account security change', $payload['body']);
        $this->assertSame(PushNotificationTypeKeys::ACCOUNT_SECURITY_CHANGE, $payload['tag']);
    }

    public function test_service_scoped_body_matches_epic_shape(): void
    {
        $payload = app(PushPayloadBuilder::class)->payloadArray(
            PushNotificationTypeKeys::TICKET_CREATED,
            new PushTarget('shelter.tickets.show', ['ticket' => 123], '123', 'Shelter and Rescue'),
        );

        $this->assertSame('New ticket #123 (Shelter and Rescue)', $payload['body']);
    }

    public function test_catalogue_covers_every_registered_type_route(): void
    {
        $catalogue = PushDeepLinkCatalogue::routesByType();

        foreach (PushNotificationTypeKeys::catalogueKeys() as $typeKey) {
            $this->assertArrayHasKey($typeKey, $catalogue, "{$typeKey} must document deep-link routes.");
            $this->assertNotEmpty($catalogue[$typeKey]);
        }
    }
}