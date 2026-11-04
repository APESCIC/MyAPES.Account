<?php

/**
 * HTML Purifier allow-list for rich text (#484). Anything not listed here
 * (scripts, event handlers, inline styles, iframes, data: URLs) is removed.
 *
 * @link http://htmlpurifier.org/live/configdoc/plain.html
 */
return [
    'encoding' => 'UTF-8',
    'finalize' => true,
    'ignoreNonStrings' => false,
    'cachePath' => storage_path('app/purifier'),
    'cacheFileMode' => 0755,
    'settings' => [
        'default' => [
            'HTML.Doctype' => 'XHTML 1.0 Transitional',
            'HTML.Allowed' => implode(',', [
                'p', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup',
                'h2', 'h3', 'h4', 'blockquote', 'code', 'pre',
                'ul', 'ol', 'li',
                'a[href|title|target]',
                'img[src|alt|width|height]',
                'table', 'thead', 'tbody', 'tr', 'th[colspan|rowspan|scope]', 'td[colspan|rowspan]',
            ]),
            'HTML.TargetBlank' => true,
            'HTML.Nofollow' => true,
            'URI.AllowedSchemes' => ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true],
            'Attr.AllowedFrameTargets' => ['_blank'],
            'AutoFormat.RemoveEmpty' => true,
            'Core.RemoveProcessingInstructions' => true,
        ],
    ],
];
