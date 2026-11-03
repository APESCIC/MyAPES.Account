<?php

namespace App\Core\Mail;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use Illuminate\Support\Facades\URL;

/**
 * Signed "Log in to reply" links for ticket and case email (#468). The
 * signature only protects the redirect target; it never signs anyone in.
 */
final class ReplyLink
{
    public const VIA_PUBLIC = 'public';

    public const VIA_STAFF = 'staff';

    public function __construct(private readonly AuthorizationProfile $profile) {}

    public function to(object $notifiable, string $itemUrl): string
    {
        $path = (string) parse_url($itemUrl, PHP_URL_PATH);
        $query = parse_url($itemUrl, PHP_URL_QUERY);

        return URL::signedRoute('mail.reply', [
            'to' => $query === null || $query === false ? $path : $path.'?'.$query,
            'via' => $this->via($notifiable),
        ]);
    }

    private function via(object $notifiable): string
    {
        return $notifiable instanceof User && $this->profile->hasDirectoryProtectedEligibility($notifiable)
            ? self::VIA_STAFF
            : self::VIA_PUBLIC;
    }
}
