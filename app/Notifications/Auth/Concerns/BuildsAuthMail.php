<?php

namespace App\Notifications\Auth\Concerns;

use App\Core\Mail\Concerns\BuildsBrandedMail;
use Illuminate\Notifications\Messages\MailMessage;

trait BuildsAuthMail
{
    use BuildsBrandedMail;

    protected function authMail(): MailMessage
    {
        return $this->brandedMail();
    }
}
