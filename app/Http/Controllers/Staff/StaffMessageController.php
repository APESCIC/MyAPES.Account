<?php

namespace App\Http\Controllers\Staff;

use App\Core\Accounts\AccountClassifier;
use App\Core\Accounts\User;
use App\Core\Content\RichText;
use App\Core\Mail\StaffMessage;
use App\Core\Mail\StaffMessageRecipientResolver;
use App\Core\Search\RecordSearch;
use App\Http\Controllers\Controller;
use App\Jobs\SendStaffMessageJob;
use App\Services\AuditLogger;
use App\Services\AuthorizationProfile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Staff workspace email campaigns (#501–#508).
 */
class StaffMessageController extends Controller
{
    public function __construct(
        private readonly AuthorizationProfile $profile,
        private readonly StaffMessageRecipientResolver $resolver,
        private readonly RichText $richText,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeHistoryOrSend($request->user());

        $messages = $this->visibleQuery($request->user())
            ->with('sender')
            ->latest()
            ->paginate(20);

        return view('staff.messages.index', [
            'messages' => $messages,
            'canSend' => $request->user()->can(AuthorizationProfile::PERMISSION_MESSAGES_SEND),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize(AuthorizationProfile::PERMISSION_MESSAGES_SEND);

        return view('staff.messages.create', $this->formContext($request->user(), new StaffMessage));
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(AuthorizationProfile::PERMISSION_MESSAGES_SEND);

        $validated = $this->validateDraft($request);
        $filter = $this->normaliseFilter($request, $request->user());
        $this->authorizeFilter($request->user(), $filter);

        $message = StaffMessage::query()->create([
            'sender_id' => $request->user()->id,
            'category' => $validated['category'],
            'subject' => $validated['subject'],
            'body_html' => $this->richText->clean($validated['body_html']) ?? '',
            'recipient_filter' => $filter,
            'status' => StaffMessage::STATUS_DRAFT,
        ]);

        return redirect()
            ->route('messages.edit', $message)
            ->with('status', __('staff_messages.flash.draft_saved'));
    }

    public function edit(Request $request, StaffMessage $message): View|RedirectResponse
    {
        Gate::authorize(AuthorizationProfile::PERMISSION_MESSAGES_SEND);
        $this->authorizeOwnMessage($request->user(), $message);

        if (! $message->isEditable()) {
            return redirect()->route('messages.show', $message);
        }

        return view('staff.messages.edit', [
            'message' => $message,
            ...$this->formContext($request->user(), $message),
        ]);
    }

    public function update(Request $request, StaffMessage $message): RedirectResponse
    {
        Gate::authorize(AuthorizationProfile::PERMISSION_MESSAGES_SEND);
        $this->authorizeOwnMessage($request->user(), $message);

        if (! $message->isEditable()) {
            abort(403);
        }

        $validated = $this->validateDraft($request);
        $filter = $this->normaliseFilter($request, $request->user());
        $this->authorizeFilter($request->user(), $filter);
        $resolution = $this->resolveCounts($request->user(), $filter, $validated['category']);

        $message->update([
            'category' => $validated['category'],
            'subject' => $validated['subject'],
            'body_html' => $this->richText->clean($validated['body_html']) ?? '',
            'recipient_filter' => $filter,
            'recipient_count' => $resolution->recipientCount(),
            'opted_out_count' => $resolution->optedOutCount,
            'status' => StaffMessage::STATUS_DRAFT,
        ]);

        return redirect()
            ->route('messages.preview', $message)
            ->with('status', __('staff_messages.flash.draft_saved'));
    }

    public function show(Request $request, StaffMessage $message): View
    {
        $this->authorizeView($request->user(), $message);

        return view('staff.messages.show', [
            'message' => $message->load('sender'),
        ]);
    }

    public function preview(Request $request, StaffMessage $message): View|RedirectResponse
    {
        Gate::authorize(AuthorizationProfile::PERMISSION_MESSAGES_SEND);
        $this->authorizeOwnMessage($request->user(), $message);

        if (! $message->isEditable()) {
            return redirect()->route('messages.show', $message);
        }

        $resolution = $this->resolveCounts(
            $request->user(),
            $message->recipient_filter,
            $message->category,
        );

        $message->update([
            'recipient_count' => $resolution->recipientCount(),
            'opted_out_count' => $resolution->optedOutCount,
            'status' => StaffMessage::STATUS_PREVIEW,
        ]);

        return view('staff.messages.preview', [
            'message' => $message->fresh('sender'),
            'requiresConfirmCount' => $this->requiresConfirmCount($message),
        ]);
    }

    public function confirm(Request $request, StaffMessage $message, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize(AuthorizationProfile::PERMISSION_MESSAGES_SEND);
        $this->authorizeOwnMessage($request->user(), $message);

        if (! in_array($message->status, [StaffMessage::STATUS_DRAFT, StaffMessage::STATUS_PREVIEW], true)) {
            abort(403);
        }

        $resolution = $this->resolveCounts(
            $request->user(),
            $message->recipient_filter,
            $message->category,
        );

        $message->update([
            'recipient_count' => $resolution->recipientCount(),
            'opted_out_count' => $resolution->optedOutCount,
        ]);

        if ($resolution->recipientCount() === 0) {
            return back()->withErrors(['recipients' => __('staff_messages.errors.no_recipients')]);
        }

        if ($this->requiresConfirmCount($message)) {
            $request->validate([
                'confirm_count' => ['required', 'integer', 'in:'.$message->recipient_count],
            ]);
        }

        $message->update([
            'status' => StaffMessage::STATUS_QUEUED,
            'queued_at' => now(),
            'sent_count' => 0,
            'failed_count' => 0,
        ]);

        $auditLogger->record(
            'staff.messages.queued',
            $request->user(),
            $message,
            [
                'staff_message_id' => $message->id,
                'recipient_count' => $message->recipient_count,
                'category' => $message->category,
                'subject' => $message->subject,
            ],
        );

        SendStaffMessageJob::dispatch($message->id);

        return redirect()
            ->route('messages.show', $message)
            ->with('status', __('staff_messages.flash.queued'));
    }

    public function userCandidates(Request $request, RecordSearch $search): \Illuminate\Http\JsonResponse
    {
        Gate::authorize(AuthorizationProfile::PERMISSION_MESSAGES_SEND);

        $q = trim((string) $request->query('q', ''));

        if (strlen($q) < 2) {
            return response()->json(['candidates' => []]);
        }

        $results = $search->search($request->user(), $q, perType: 15);
        $hits = collect($results['groups'])->firstWhere('type', 'users')['hits'] ?? [];

        $candidates = collect($hits)
            ->map(function (array $hit): ?array {
                $user = User::query()->find($hit['id']);

                if ($user === null || $user->suspended_at !== null || ! filled($user->email)) {
                    return null;
                }

                return [
                    'id' => $user->id,
                    'label' => $user->name,
                    'detail' => $user->email,
                ];
            })
            ->filter()
            ->values();

        return response()->json(['candidates' => $candidates]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formContext(User $user, StaffMessage $message): array
    {
        $saved = is_array($message->recipient_filter) ? $message->recipient_filter : [];

        return [
            'canSendAll' => $user->can(AuthorizationProfile::PERMISSION_MESSAGES_SEND_ALL),
            'filterMode' => old('filter_mode', $saved['mode'] ?? 'group'),
            'selectedGroup' => old('filter_group', $saved['group'] ?? AccountClassifier::PUBLIC),
            'selectedModule' => old('filter_module', $saved['module'] ?? ''),
            'selectedUserIds' => old('filter_user_ids', $saved['user_ids'] ?? []),
            'moduleOptions' => collect($this->resolver->allowedModuleKeys($user))
                ->map(static fn (string $key): array => ['key' => $key, 'label' => $key])
                ->values()
                ->all(),
            'groups' => [
                AccountClassifier::PUBLIC => __('staff_messages.filters.group_public'),
                AccountClassifier::STUDENT => __('staff_messages.filters.group_student'),
                AccountClassifier::VOLUNTEER => __('staff_messages.filters.group_volunteer'),
                AccountClassifier::STAFF => __('staff_messages.filters.group_staff'),
                AccountClassifier::ADMIN => __('staff_messages.filters.group_admin'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateDraft(Request $request): array
    {
        return $request->validate([
            'category' => ['required', Rule::in([StaffMessage::CATEGORY_SERVICE, StaffMessage::CATEGORY_OPTIONAL])],
            'subject' => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string', 'max:65000'],
            'filter_mode' => ['required', Rule::in(['all', 'group', 'module', 'users'])],
            'filter_group' => ['nullable', 'required_if:filter_mode,group', 'string'],
            'filter_module' => ['nullable', 'required_if:filter_mode,module', 'string'],
            'filter_user_ids' => ['nullable', 'array'],
            'filter_user_ids.*' => ['integer', 'exists:users,id'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function normaliseFilter(Request $request, User $user): array
    {
        $mode = (string) $request->input('filter_mode');

        $filter = ['mode' => $mode];

        return match ($mode) {
            'all' => $filter,
            'group' => array_merge($filter, ['group' => (string) $request->input('filter_group')]),
            'module' => array_merge($filter, ['module' => (string) $request->input('filter_module')]),
            'users' => array_merge($filter, [
                'user_ids' => collect($request->input('filter_user_ids', []))
                    ->map(static fn ($id): int => (int) $id)
                    ->filter(static fn (int $id): bool => $id > 0)
                    ->values()
                    ->all(),
            ]),
            default => $filter,
        };
    }

    private function resolveCounts(User $sender, array $filter, string $category): \App\Core\Mail\StaffMessageRecipientResolution
    {
        return $this->resolver->resolve(
            $sender,
            $filter,
            $category,
            $sender->can(AuthorizationProfile::PERMISSION_MESSAGES_SEND_ALL),
        );
    }

    private function requiresConfirmCount(StaffMessage $message): bool
    {
        $filter = $message->recipient_filter;

        return $message->recipient_count > 100
            || (($filter['mode'] ?? null) === 'all');
    }

    private function authorizeHistoryOrSend(User $user): void
    {
        if (! $user->can(AuthorizationProfile::PERMISSION_MESSAGES_SEND)
            && ! $user->can(AuthorizationProfile::PERMISSION_MESSAGES_HISTORY_VIEW)) {
            abort(403);
        }
    }

    private function authorizeView(User $user, StaffMessage $message): void
    {
        if ($this->canViewAll($user)) {
            return;
        }

        if ($user->id === $message->sender_id
            && ($user->can(AuthorizationProfile::PERMISSION_MESSAGES_SEND)
                || $user->can(AuthorizationProfile::PERMISSION_MESSAGES_HISTORY_VIEW))) {
            return;
        }

        abort(403);
    }

    private function authorizeOwnMessage(User $user, StaffMessage $message): void
    {
        if ($this->canViewAll($user)) {
            return;
        }

        if ($user->id !== $message->sender_id) {
            abort(403);
        }
    }

    private function canViewAll(User $user): bool
    {
        return $this->profile->isAdministrator($user)
            || ($user->can(AuthorizationProfile::PERMISSION_MESSAGES_SEND_ALL)
                && $user->can(AuthorizationProfile::PERMISSION_MESSAGES_HISTORY_VIEW));
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    private function authorizeFilter(User $user, array $filter): void
    {
        $mode = $filter['mode'] ?? '';

        if ($mode === 'all' && ! $user->can(AuthorizationProfile::PERMISSION_MESSAGES_SEND_ALL)) {
            abort(403);
        }

        if ($mode === 'module'
            && ! $user->can(AuthorizationProfile::PERMISSION_MESSAGES_SEND_ALL)
            && ! in_array((string) ($filter['module'] ?? ''), $this->resolver->allowedModuleKeys($user), true)) {
            abort(403);
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<StaffMessage>
     */
    private function visibleQuery(User $user): \Illuminate\Database\Eloquent\Builder
    {
        $query = StaffMessage::query();

        if ($this->canViewAll($user)) {
            return $query;
        }

        return $query->where('sender_id', $user->id);
    }
}