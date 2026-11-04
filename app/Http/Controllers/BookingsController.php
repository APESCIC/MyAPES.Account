<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Public Microsoft Bookings pages (#488): each embeds the service's booking
 * page, with a "Schedule online" link that opens it in a new tab in case the
 * frame cannot load.
 */
class BookingsController extends Controller
{
    public function index(): View
    {
        return view('bookings.index', ['pages' => $this->pages()]);
    }

    public function show(string $page): View
    {
        $pages = $this->pages();
        abort_unless(isset($pages[$page]), 404);

        return view('bookings.show', [
            'pages' => $pages,
            'current' => $page,
            'booking' => $pages[$page],
        ]);
    }

    /**
     * @return array<string, array{slug: string, title: string, intro: string, url: string}>
     */
    private function pages(): array
    {
        $copy = [
            'apes' => [__('bookings.pages.apes.title'), __('bookings.pages.apes.intro')],
            'shelter-and-rescue' => [__('bookings.pages.shelter_and_rescue.title'), __('bookings.pages.shelter_and_rescue.intro')],
            'pet-care-clinic' => [__('bookings.pages.pet_care_clinic.title'), __('bookings.pages.pet_care_clinic.intro')],
        ];

        /** @var array<string, string> $urls */
        $urls = config('myapes.bookings.pages', []);

        $pages = [];
        foreach ($urls as $slug => $url) {
            if (isset($copy[$slug]) && str_starts_with($url, 'https://')) {
                $pages[$slug] = ['slug' => $slug, 'title' => $copy[$slug][0], 'intro' => $copy[$slug][1], 'url' => $url];
            }
        }

        return $pages;
    }
}
