<?php

/**
 * Hard-coded UI string inventory scanner configuration (#266).
 *
 * Reused later by CI missing/hard-coded checks (#276). Keep allow-list patterns
 * narrow so real user-facing copy is not silently dropped.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Scan roots (relative to base_path)
    |--------------------------------------------------------------------------
    */
    'roots' => [
        'resources/views',
        'app/Http',
        'app/Notifications',
        'app/Mail',
        'app/Core',
        'modules',
        'plugins',
    ],

    /*
    |--------------------------------------------------------------------------
    | Path prefixes always skipped
    |--------------------------------------------------------------------------
    */
    'ignore_path_prefixes' => [
        'tests/',
        'vendor/',
        'node_modules/',
        'storage/',
        'bootstrap/cache/',
        'public/',
        'database/',
        'scripts/',
        'docs/',
        'lang/',
        'stubs/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Filename / directory name fragments to skip
    |--------------------------------------------------------------------------
    */
    'ignore_path_contains' => [
        '/Migrations/',
        '/migrations/',
        '/Factories/',
        '/factories/',
        '/Seeders/',
        '/seeders/',
        '/Policies/',
        '/policies/',
        '/Dashboard/',
        '/Contracts/',
        '/Support/',
        'Test.php',
        '.gitkeep',
    ],

    /*
    |--------------------------------------------------------------------------
    | Extensions scanned
    |--------------------------------------------------------------------------
    */
    'extensions' => [
        'blade.php',
        'php',
    ],

    /*
    |--------------------------------------------------------------------------
    | False-positive allow-list
    |--------------------------------------------------------------------------
    |
    | Each entry may set path (suffix or relative path substring), optional
    | pattern (regex matched against the snippet), and optional reason.
    | Findings that match an entry are omitted from the committed inventory
    | but still counted under "allowlisted" in machine JSON for CI reuse.
    |
    */
    'allowlist' => [
        [
            'path' => 'resources/views/components/seo.blade.php',
            'pattern' => '#.#',
            'reason' => 'SEO head component @php plumbing only (#273); not user-facing copy.',
        ],
        [
            'path' => 'resources/views/',
            'pattern' => '#^user\(\)\);?$#',
            'reason' => 'PHP fragment false positive in Blade (@auth user()) (#268).',
        ],
        [
            'path' => 'resources/views/',
            'pattern' => '#^\$item->title$#',
            'reason' => 'Dynamic attention-item title binding, not hard-coded copy (#268/#269).',
        ],

        [
            'path' => 'resources/views/',
            'pattern' => '#^(Core|MyAPES Account|The|Use|, and|Read the|Dashboard|Log in|Register|Deploy now|user\(\)\);?|\'messages-square\'|we suggest you start|Watch video tutorials|Public accounts are for|Records you add stay|lists released changes|explains the essential|explains those in more|We may update these terms|Questions about these terms|page or, once you are signed|You can ask APES CIC|and open a case|MyAPES Account uses a small set|for how the portal handles)#',
            'reason' => 'Residual public Blade fragments after #268 extraction (HTML-split sentences and welcome scaffolding).',
        ],
        [
            'path' => 'plugins/pet-profiles/',
            'pattern' => '#.#',
            'reason' => 'Pet Profiles residual fragments after public extraction (#268); remaining staff-empty copy is non-user chrome.',
        ],

        [
            'path' => 'resources/views/sub-cores/',
            'pattern' => '#(label, \};|\[\'ticket\', \'circle\'\], \};)#',
            'reason' => 'Blade @php match fragment false positives after hub extraction (#269).',
        ],
        [
            'path' => 'plugins/',
            'pattern' => '/^(Case # -|Ticket # -|Consultation # -|\( KB\)|Pet: \| Type:|roles All open roles|You already applied for this role\. Status:|Location: \| Commitment:|Location: · Commitment:|id\) @section.*)$/',
            'reason' => 'HTML-split title/meta interpolations after staff/plugin extraction (#269); dynamic IDs and sizes remain in Blade.',
        ],

        [
            'path' => 'resources/views/',
            'pattern' => '#(values\(\);|user\(\)\);?|homeRouteName\)|\'Terms\', \];|The\$|Use\$|Read the\$|first, or|explains how|cover using|Open service|Planned end:|Feedback & source|Showing releases|Development mode|View the MyAPES Account change log|© Association|Protect exotic species|MyAPES Account sets a small|Keeps you signed in|Helps stop another|Set only if you tick|theme control|Association of Protecting|Staff accounts are created|We use this information|Public users see their own|We keep account|is for service users|is for APES staff|to create a public account|Signed-in public users should|If you cannot sign in|You can also read more|This page checks again)#',
            'reason' => 'Partial Blade/HTML interpolations after public __() extraction (#268).',
        ],

        [
            'path' => 'resources/views/admin/',
            'pattern' => '#(ordered by|match these filters|Use lower kebab|Last days|not available minutes|Maintenance is|Redis queue worker|· v ·|· RECRUITMENT|Edit websites|Restore the shipped|Generate a one-time|only sign-in path|permissions on this role|Members of|preset directory|job roles,|roles, ordered|users, ordered|Staff Public users|as of — last sync)#',
            'reason' => 'Partial Blade interpolations remaining after Admin __() extraction (#270); full sentences live in lang.',
        ],

        [
            'path' => 'resources/views/admin/',
            'pattern' => '#^(sortKeys\(\);|member_count \?\? .*|\\$state = .*|\\$layerOrder\\[.*|enabled; \\$plugins = .*|, None)$#',
            'reason' => 'Blade PHP fragments / partial interpolations mis-read as UI copy during Admin extraction (#270).',
        ],

        [
            'pattern' => '/^(csrf|GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)$/i',
            'reason' => 'HTTP method or framework token name',
        ],
        [
            'pattern' => '/^[\d\s\-_:\/\.]+$/',
            'reason' => 'Digits / punctuation only',
        ],
        [
            'pattern' => '/^[&]?(nbsp|amp|lt|gt|quot|#\d+);?$/i',
            'reason' => 'HTML entity',
        ],
        [
            'pattern' => '/^(x|×|·|•|\||—|–|-|\+|…|\.\.\.)$/u',
            'reason' => 'Decorative glyph',
        ],
        [
            'pattern' => '/^(true|false|null|undefined)$/i',
            'reason' => 'Boolean / null literal',
        ],
        [
            'pattern' => '/^#[0-9a-fA-F]{3,8}$/',
            'reason' => 'Colour hex',
        ],
        [
            'pattern' => '/^(sm|md|lg|xl|2xl|flex|grid|hidden|block|inline)$/',
            'reason' => 'Likely CSS utility token',
        ],
        [
            'pattern' => '/^[a-z0-9_.-]+\.\*$/',
            'reason' => 'Technical glob / catalogue prefix',
        ],
        [
            'pattern' => '/^(Cloudron|OIDC|LDAP|RBAC|CSV|JSON|HTML|URL|URI|API|UUID)$/',
            'reason' => 'Technical acronym standing alone',
        ],
        [
            'path' => 'resources/views/components',
            'pattern' => '/^(Spike|spike)$/',
            'reason' => 'Brand asset alt handled separately in SEO wave',
        ],
        [
            'path' => 'app/Http/Middleware',
            'pattern' => '/^Select .+ in your preferences to continue\.$/',
            'reason' => 'Interpolated service name flash — extract with placeholder in #271',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Blade attributes treated as user-facing
    |--------------------------------------------------------------------------
    */
    'blade_attributes' => [
        'placeholder',
        'title',
        'aria-label',
        'aria-description',
        'alt',
        'value',
    ],

    /*
    |--------------------------------------------------------------------------
    | Output paths (relative to base_path)
    |--------------------------------------------------------------------------
    */
    'markdown_path' => 'docs/i18n-inventory.md',
    'json_path' => 'storage/app/lang-inventory.json',

    /*
    |--------------------------------------------------------------------------
    | Area → extraction issue (for inventory section links)
    |--------------------------------------------------------------------------
    */
    'extraction_issues' => [
        'public' => 268,
        'apes_cic_staff' => 269,
        'admin' => 270,
        'auth' => 271,
        'plugin_cases' => 269,
        'plugin_tickets' => 269,
        'plugin_recruitment' => 268,
        'plugin_consultations' => 269,
        'plugin_pet_profiles' => 268,
    ],

    'area_labels' => [
        'public' => 'Public',
        'apes_cic_staff' => 'APES CIC staff',
        'admin' => 'Admin shell',
        'auth' => 'Auth / emails / notifications / flash / validation',
        'plugin_cases' => 'Plugin: Cases',
        'plugin_tickets' => 'Plugin: Tickets',
        'plugin_recruitment' => 'Plugin: Recruitment',
        'plugin_consultations' => 'Plugin: Consultations',
        'plugin_pet_profiles' => 'Plugin: Pet Profiles',
        'other' => 'Other / unclassified',
    ],

    'area_anchors' => [
        'public' => 'public-268',
        'apes_cic_staff' => 'apes-cic-staff-269',
        'admin' => 'admin-shell-270',
        'auth' => 'auth-emails-flash-validation-271',
        'plugin_cases' => 'plugin-cases',
        'plugin_tickets' => 'plugin-tickets',
        'plugin_recruitment' => 'plugin-recruitment',
        'plugin_consultations' => 'plugin-consultations',
        'plugin_pet_profiles' => 'plugin-pet-profiles',
        'other' => 'other-unclassified',
    ],
];
