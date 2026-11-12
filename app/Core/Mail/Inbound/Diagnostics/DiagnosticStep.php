<?php

namespace App\Core\Mail\Inbound\Diagnostics;

/**
 * The steps of a mailbox connection test, in the order they run (#771).
 */
enum DiagnosticStep: string
{
    case Dns = 'dns';
    case Tcp = 'tcp';
    case Tls = 'tls';
    case Login = 'login';
    case Folder = 'folder';

    public function label(): string
    {
        return __("admin.emails.import.steps.{$this->value}");
    }

    /**
     * The failure reported when this step breaks in a way no more specific type explains.
     */
    public function defaultFailure(): FailureType
    {
        return match ($this) {
            self::Dns => FailureType::DnsNotFound,
            self::Tcp => FailureType::ConnectionRefused,
            self::Tls => FailureType::HandshakeFailed,
            self::Login => FailureType::LoginFailed,
            self::Folder => FailureType::FolderNotFound,
        };
    }
}
