<?php

namespace App\Http\Controllers;

use App\Support\Tour\GuidedTour;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Saves that the signed-in user finished or skipped their guided tour (#489).
 */
class GuidedTourController extends Controller
{
    public function complete(Request $request, GuidedTour $tour, string $audience): Response
    {
        $user = $request->user();
        abort_unless($user !== null && $audience === $tour->audienceFor($user), 404);

        $tour->finish($user, $audience, $request->boolean('skipped'));

        return response()->noContent();
    }
}
