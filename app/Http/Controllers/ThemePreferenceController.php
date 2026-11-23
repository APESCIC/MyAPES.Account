<?php

namespace App\Http\Controllers;

use App\Support\Theme\ThemePreference;
use App\Support\Theme\ThemeRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ThemePreferenceController extends Controller
{
    public function store(Request $request, ThemePreference $themePreference): JsonResponse
    {
        $modes = config('themes.modes', ['light', 'dark']);

        $validated = $request->validate([
            'skin' => ['required', 'string', Rule::in(array_keys(ThemeRegistry::skins()))],
            'mode' => ['required', 'string', Rule::in($modes)],
            'calm' => ['required', 'boolean'],
        ]);

        $user = $request->user();

        $themePreference->saveForUser(
            $user,
            $validated['skin'],
            $validated['mode'],
            (bool) $validated['calm'],
        );

        return response()->json([
            'appearance' => $themePreference->resolve($user),
        ]);
    }
}