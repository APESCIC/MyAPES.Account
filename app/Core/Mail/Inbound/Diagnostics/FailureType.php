<?php

namespace App\Core\Mail\Inbound\Diagnostics;

/**
 * Why a mailbox test or poll failed, in words an administrator can act on
 * (#772). Never carries server text, usernames or passwords.
 */
enum FailureType: string
{
    case DnsNotFound = 'dns_not_found';
    case ConnectionRefused = 'connection_refused';
    case Timeout = 'timeout';
    case WrongEncryption = 'wrong_encryption';
    case CertificateNameMismatch = 'certificate_name_mismatch';
    case CertificateUntrusted = 'certificate_untrusted';
    case HandshakeFailed = 'handshake_failed';
    case LoginFailed = 'login_failed';
    case TooManyAttempts = 'too_many_attempts';
    case FolderNotFound = 'folder_not_found';
    case FeatureMissing = 'feature_missing';

    public function message(): string
    {
        return __("admin.emails.import.errors.{$this->value}.message");
    }

    public function hint(): string
    {
        return __("admin.emails.import.errors.{$this->value}.hint");
    }

    public function isWarning(): bool
    {
        return $this === self::FeatureMissing;
    }
}
