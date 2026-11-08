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

    'types' => [
        'screenshots' => 'screenshots',
        'screencasts' => 'screencasts',
        'documents' => 'documents',
    ],
];
