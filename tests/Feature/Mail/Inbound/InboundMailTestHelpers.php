<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\ReplyToken;
use App\Core\Secrets\SecretRegistry;
use Illuminate\Support\Collection;
use Plugins\Cases\Models\ShelterCase;
use Plugins\PetProfiles\Models\PetProfile;
use Plugins\Tickets\Models\SupportTicket;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

/**
 * Shared set-up for the replies-by-email tests (#589).
 */
trait InboundMailTestHelpers
{
    protected function enableEmailReplies(array $overrides = []): void
    {
        $password = array_key_exists('inbound-mail.imap.password', $overrides)
            ? $overrides['inbound-mail.imap.password']
            : 'test-inbound-password';
        unset($overrides['inbound-mail.imap.password'], $overrides['app-settings.env.INBOUND_MAIL_IMAP_PASSWORD']);

        config([
            'inbound-mail.enabled' => true,
            'inbound-mail.address' => 'communications@example.test',
            'inbound-mail.plus_addressing' => true,
            'inbound-mail.token_key' => 'inbound-test-key',
            'inbound-mail.require_authentication' => true,
            'inbound-mail.authserv_id' => 'mx.example.test',
            'inbound-mail.imap.host' => 'mail.cloudron.apes.org.uk',
            'inbound-mail.imap.port' => 993,
            'inbound-mail.imap.encryption' => 'ssl',
            'inbound-mail.imap.password' => null,
            'app-settings.env.INBOUND_MAIL_IMAP_PASSWORD' => null,
            'mail.default' => 'array',
            ...$overrides,
        ]);

        $secrets = app(SecretRegistry::class);
        if (is_string($password) && $password !== '') {
            $secrets->set(SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD, $password);
        } else {
            $secrets->clear(SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD);
        }
    }

    protected function ticketFor(User $owner, array $attributes = []): SupportTicket
    {
        return SupportTicket::query()->create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $owner->id,
            'service_area' => 'operations_facilities',
            'sub_category' => 'premises',
            'subject' => 'Boiler is broken',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'The boiler in the shelter office is broken.',
            ...$attributes,
        ]);
    }

    protected function apesCicCaseFor(User $owner, array $attributes = []): ShelterCase
    {
        return ShelterCase::query()->create([
            'sub_core_key' => ShelterCase::SUB_CORE_APES_CIC,
            'user_id' => $owner->id,
            'category' => 'general',
            'priority' => 'medium',
            'status' => 'open',
            'title' => 'Email reply case',
            'details' => 'Case used for replies by email.',
            ...$attributes,
        ]);
    }

    protected function shelterCaseFor(User $owner, array $attributes = []): ShelterCase
    {
        $pet = PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_SHELTER,
            'name' => 'Biscuit',
            'species' => 'dog',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ]);

        return ShelterCase::query()->create([
            'sub_core_key' => ShelterCase::SUB_CORE_SHELTER_RESCUE,
            'pet_profile_id' => $pet->id,
            'user_id' => $owner->id,
            'case_type' => 'surrender',
            'status' => 'in_review',
            'title' => 'Shelter email case',
            'details' => 'Case used for replies by email.',
            ...$attributes,
        ]);
    }

    protected function replyAddressFor(string $prefix, int $recordId, User $user): string
    {
        return 'communications+'.app(ReplyToken::class)->localTag($prefix, $recordId, $user->id).'@example.test';
    }

    /**
     * @return list<Email>
     */
    protected function sentEmails(): array
    {
        /** @var Collection<int, SentMessage> $messages */
        $messages = app('mailer')->getSymfonyTransport()->messages();

        return $messages->map(static fn (SentMessage $message): Email => $message->getOriginalMessage())->values()->all();
    }

    /**
     * Build a raw RFC 5322 reply from a fixture in tests/Fixtures/InboundMail.
     *
     * @param  array<string, string>  $values
     */
    protected function fixture(string $name, array $values = []): string
    {
        $raw = (string) file_get_contents(base_path('tests/Fixtures/InboundMail/'.$name.'.eml'));
        $values += [
            'FROM' => 'owner@example.org',
            'TO' => 'communications@example.test',
            'SUBJECT' => 'Re: Ticket update',
            'MESSAGE_ID' => 'reply-'.bin2hex(random_bytes(6)).'@mail.example.org',
            'IN_REPLY_TO' => '<none@example.test>',
            'AUTH' => 'mx.example.test; spf=pass smtp.mailfrom=example.org; dkim=pass header.d=example.org; dmarc=pass header.from=example.org',
            'BODY' => 'Thanks, that fixed it.',
            'EXTRA' => '',
        ];

        foreach ($values as $key => $value) {
            $raw = str_replace('{{'.$key.'}}', $value, $raw);
        }

        return str_replace(["\r\n", "\n"], ["\n", "\r\n"], $raw);
    }
}
