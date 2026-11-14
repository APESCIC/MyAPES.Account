<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Core\Attachments\AttachmentLimits;
use App\Core\Attachments\UploadLimitsProbe;
use App\Core\Settings\AppSettings;
use App\Core\Settings\GeneralSettings;
use App\Http\Controllers\Admin\AdminSettingsController;

/**
 * What Admin → Settings → General shows (#734): the editable general options,
 * read-only retention and upload limits (set in .env and code, not here) and
 * links to the pages that hold the other set-up.
 */
final class GeneralTab
{
    public function __construct(
        private readonly AppSettings $settings,
        private readonly UploadLimitsProbe $uploads,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        $values = [];
        foreach ($this->settings->definitions(GeneralSettings::GROUP) as $key => $definition) {
            $values[$key] = $this->settings->get(GeneralSettings::GROUP, $key);
        }

        $days = static fn (int $count): string => trans_choice('admin.settings.general.days', $count, ['count' => $count]);
        $months = static fn (int $count): string => trans_choice('admin.settings.general.months', $count, ['count' => $count]);
        $php = $this->uploads->limits();

        return [
            'values' => $values,
            'bannerMaxLength' => GeneralSettings::BANNER_MAX_LENGTH,
            'retention' => [
                'audit' => $days((int) config('myapes.audit.retention_days')),
                'call_logs' => $months((int) config('myapes.call_logs.retention_months')),
                'mail_logs' => $days((int) config('myapes.mail.log_retention_days')),
                'imported_mail' => $days((int) config('inbound-mail.retention_days')),
                'mailbox_folders' => $days((int) config('inbound-mail.folder_retention_days')),
            ],
            'uploads' => [
                'screenshots' => __('admin.settings.general.uploads.limit', ['files' => AttachmentLimits::SCREENSHOT_MAX_FILES, 'size' => AttachmentLimits::megabytes(AttachmentLimits::SCREENSHOT_MAX_KB)]),
                'screencasts' => __('admin.settings.general.uploads.limit', ['files' => AttachmentLimits::SCREENCAST_MAX_FILES, 'size' => AttachmentLimits::megabytes(AttachmentLimits::SCREENCAST_MAX_KB)]),
                'documents' => __('admin.settings.general.uploads.limit', ['files' => AttachmentLimits::DOCUMENT_MAX_FILES, 'size' => AttachmentLimits::megabytes(AttachmentLimits::DOCUMENT_MAX_KB)]),
                'submission' => AttachmentLimits::megabytes(AttachmentLimits::SUBMISSION_MAX_KB),
                'php' => __('admin.settings.general.uploads.php_limits', [
                    'file' => $php['upload_max_filesize']['effective'] ?? '?',
                    'post' => $php['post_max_size']['effective'] ?? '?',
                    'files' => $php['max_file_uploads']['effective'] ?? '?',
                ]),
            ],
            'links' => [
                'maintenance' => route('admin.maintenance.index'),
                'integrations' => route('admin.settings.show', AdminSettingsController::TAB_INTEGRATIONS),
                'modules' => route('admin.organisation-modules.index'),
                'emails_inbound' => route('admin.emails.inbound.index'),
                'emails_import' => route('admin.emails.import.index'),
            ],
        ];
    }
}
