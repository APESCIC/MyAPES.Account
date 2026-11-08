<?php

return [
    'kinds' => [
        'screenshot' => 'Screenshot',
        'screencast' => 'Screencast',
        'document' => 'Document',
    ],

    'validation' => [
        'document_type' => ':name is not an allowed document. Use PDF, DOCX, XLSX, ODT, ODS, TXT or CSV.',
        'document_macros' => ':name contains macros. Save it without macros and try again.',
        'document_unreadable' => ':name could not be read. Try uploading it again.',
        'file_too_large' => ':name is larger than :size.',
        'too_many' => 'You can attach up to :max :type at a time.',
        'each_too_large' => 'Each :type file must be :size or smaller.',
        'wrong_type' => 'Each :type file must be one of: :types.',
        'total_too_large' => 'Files in one submission must add up to :size or less.',
        'uploads_disabled' => 'Uploads are turned off for this service.',
        'not_available' => 'Attachments are not available here.',
    ],

    'component' => [
        'legend' => 'Attachments (optional)',
        'screenshots' => 'Screenshots',
        'screencasts' => 'Screencasts',
        'documents' => 'Documents',
        'limits' => 'Up to :max files, :size each. :types.',
        'total_limit' => 'All files together can be up to :size.',
        'drop_or_choose' => 'Drop files here or choose files',
        'selected' => ':count selected.',
        'remove' => 'Remove :name',
        'rejected_type' => ':name was not added: use :types.',
        'rejected_size' => ':name was not added: it is larger than :size.',
        'rejected_count' => ':name was not added: you can attach up to :max files here.',
        'rejected_total' => ':name was not added: all files together must be :total or less.',
        'upload_failed' => 'The upload did not finish. Check your files and try again.',
    ],

    'list' => [
        'staff_only' => 'Staff only',
        'delete' => 'Delete',
        'delete_named' => 'Delete :name',
        'confirm_delete' => 'Delete this attachment?',
    ],

    'scan' => [
        'pending' => 'Checking for viruses',
        'infected' => 'Blocked: a virus was found',
    ],

    'flash' => [
        'deleted' => ':name was deleted.',
    ],

    'types' => [
        'screenshots' => 'screenshots',
        'screencasts' => 'screencasts',
        'documents' => 'documents',
    ],
];
