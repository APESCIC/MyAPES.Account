<?php

namespace Plugins\PetProfiles;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Plugins\PetProfiles\Models\PetProfile;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PetProfilePhotoResponder
{
    public function response(PetProfile $pet, string $domain): StreamedResponse
    {
        abort_unless(Gate::allows('view', [$pet, $domain]), 404);

        $path = $pet->photo_path;
        abort_unless(
            is_string($path)
                && preg_match(
                    '/\Apet-profiles\/[A-Za-z0-9]{40}\.(?:jpe?g|png|webp)\z/iD',
                    $path,
                ) === 1,
            404,
        );

        $disk = Storage::disk('public');
        abort_unless($disk->exists($path), 404);
        $mime = $disk->mimeType($path);
        abort_unless(
            is_string($mime)
                && in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true),
            404,
        );

        return $disk->response($path, basename($path), [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
