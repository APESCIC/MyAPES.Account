<?php

namespace App\Core\Mail;

enum MailCategory: string
{
    case Security = 'security';
    case Service = 'service';
    case Optional = 'optional';

    /**
     * Mandatory mail ignores the recipient's Email contact preference.
     */
    public function isMandatory(): bool
    {
        return match ($this) {
            self::Security, self::Service => true,
            self::Optional => false,
        };
    }

    public function label(): string
    {
        return __('mail.categories.'.$this->value);
    }
}
