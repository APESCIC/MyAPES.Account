<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Per-release changelog directory
    |--------------------------------------------------------------------------
    |
    | Newsroom-style release records live as v{X.Y.Z}.json files under this
    | path. The published releases.json history is unchanged until #706.
    |
    */
    'changelog_path' => env('RELEASES_CHANGELOG_PATH', base_path('changelog/releases')),
];