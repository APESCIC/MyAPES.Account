<?php

namespace App\Http\Controllers\Staff;

use App\Core\Accounts\CallerVerificationService;
use App\Core\Accounts\User;
use App\Http\Controllers\Controller;
use App\Services\AuthorizationProfile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Staff, volunteers, students and administrators find a caller and check
 * their identity with two PIN digits (#477).
 */
class CallerController extends Controller
{
    public function __construct(
        private readonly AuthorizationProfile $profile,
        private readonly CallerVerificationService $verification,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeStaff($request);
        $term = trim((string) $request->query('q', ''));
        $callers = [];

        if (mb_strlen($term) >= 2) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $callers = User::query()
                ->where(static fn ($query) => $query
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('username', 'like', $like))
                ->orderBy('name')
                ->limit(25)
                ->get()
                ->reject(fn (User $user): bool => $this->profile->hasDirectoryProtectedEligibility($user))
                ->values();
        }

        return view('callers.index', ['term' => $term, 'callers' => $callers]);
    }

    public function show(Request $request, User $caller): View
    {
        $this->authorizeStaff($request);
        $this->assertCaller($caller);

        return view('callers.show', [
            'caller' => $caller,
            'positions' => $this->verification->positions($request->session(), $caller),
            'lockedForSeconds' => $this->verification->lockedForSeconds($caller),
            'verified' => $request->session()->has($this->verification->sessionKey($caller, 'verified_at')),
        ]);
    }

    public function verify(Request $request, User $caller): RedirectResponse
    {
        $this->authorizeStaff($request);
        $this->assertCaller($caller);

        $digits = $request->validate([
            'pin_digits' => ['required', 'array', 'size:2'],
            'pin_digits.*' => ['required', 'digits:1'],
        ])['pin_digits'];

        $result = $this->verification->attempt($request->session(), $request->user(), $caller, $digits);

        if ($result === CallerVerificationService::RESULT_VERIFIED) {
            $request->session()->put($this->verification->sessionKey($caller, 'verified_at'), now()->getTimestamp());
        }

        return redirect()
            ->route('callers.show', $caller)
            ->with('status', match ($result) {
                CallerVerificationService::RESULT_VERIFIED => __('callers.result.verified'),
                CallerVerificationService::RESULT_LOCKED => __('callers.result.locked'),
                default => __('callers.result.failed'),
            });
    }

    private function authorizeStaff(Request $request): void
    {
        abort_unless($this->profile->hasDirectoryProtectedEligibility($request->user()), 403);
    }

    private function assertCaller(User $caller): void
    {
        abort_if($this->profile->hasDirectoryProtectedEligibility($caller), 404);
    }
}
