<?php

namespace App\Http\Controllers\Staff;

use App\Core\Accounts\CallerVerificationService;
use App\Core\Accounts\CallLog;
use App\Core\Accounts\User;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\AuthorizationProfile;
use App\Services\UkPhoneNumber;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff, volunteers, students and administrators find a caller, check
 * their identity with two PIN digits (#477), and only then edit their
 * details until End call or the 60-minute timeout (#478).
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
        $unlocked = $this->verification->isUnlocked($request->session(), $request->user(), $caller);

        return view('callers.show', [
            'caller' => $caller,
            'details' => $caller->profile,
            'positions' => $unlocked ? null : $this->verification->positions($request->session(), $caller),
            'lockedForSeconds' => $this->verification->lockedForSeconds($caller),
            'verified' => $unlocked,
            'unlockedUntil' => $unlocked ? $this->verification->unlockedUntil($request->session(), $caller) : null,
            'callLogs' => CallLog::query()->where('caller_id', $caller->id)->with('staff:id,name')->latest('called_at')->latest('id')->limit(50)->get(),
        ]);
    }

    public function storeCallLog(Request $request, User $caller, AuditLogger $audit): RedirectResponse
    {
        $this->authorizeStaff($request);
        $this->assertCaller($caller);
        abort_unless($this->verification->isUnlocked($request->session(), $request->user(), $caller), 403);

        $validated = $request->validate([
            'direction' => ['required', Rule::in(CallLog::DIRECTIONS)],
            'summary' => ['required', 'string', 'max:2000'],
        ]);

        $log = CallLog::query()->create([
            'caller_id' => $caller->id,
            'staff_id' => $request->user()->id,
            'direction' => $validated['direction'],
            'summary' => $validated['summary'],
            'called_at' => now(),
        ]);
        $audit->record('caller.call_logged', $request->user(), $caller, ['call_log_id' => $log->id]);

        return redirect()->to(route('callers.show', $caller).'#call-logs')->with('status', __('callers.call_log_saved'));
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

        return redirect()
            ->route('callers.show', $caller)
            ->with('status', match ($result) {
                CallerVerificationService::RESULT_VERIFIED => __('callers.result.verified'),
                CallerVerificationService::RESULT_LOCKED => __('callers.result.locked'),
                default => __('callers.result.failed'),
            });
    }

    public function endCall(Request $request, User $caller): RedirectResponse
    {
        $this->authorizeStaff($request);
        $this->assertCaller($caller);

        $this->verification->endCall($request->session(), $request->user(), $caller);

        return redirect()->route('callers.show', $caller)->with('status', __('callers.call_ended'));
    }

    public function updateDetails(Request $request, User $caller, UkPhoneNumber $phones, AuditLogger $audit): RedirectResponse
    {
        $this->authorizeStaff($request);
        $this->assertCaller($caller);
        abort_unless($this->verification->isUnlocked($request->session(), $request->user(), $caller), 403);

        $request->merge([
            'mobile_number' => $phones->normalize($request->input('mobile_number'), 'mobile_number'),
            'landline_number' => $phones->normalize($request->input('landline_number'), 'landline_number', false),
        ]);
        $validated = $request->validate([
            'address_line_1' => ['required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'town_city' => ['required', 'string', 'max:255'],
            'county' => ['nullable', 'string', 'max:255'],
            'postcode' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z]{1,2}\d[A-Za-z\d]?\s*\d[A-Za-z]{2}$/'],
            'mobile_number' => ['required', 'string', 'max:32', 'regex:/^\+44\d{9,10}$/'],
            'landline_number' => ['nullable', 'string', 'max:32', 'regex:/^\+44\d{9,10}$/'],
        ]);
        $validated['postcode'] = strtoupper((string) preg_replace('/\s+/', ' ', trim($validated['postcode'])));
        $validated['phone'] = $validated['mobile_number'];

        $profile = $caller->profile()->firstOrNew(['user_id' => $caller->id]);
        $profile->fill($validated);
        $changed = array_keys($profile->getDirty());
        $profile->save();

        $audit->record('caller.details_updated', $request->user(), $caller, ['fields' => $changed]);

        return redirect()->route('callers.show', $caller)->with('status', __('callers.details_saved'));
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
