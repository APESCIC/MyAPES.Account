<?php

namespace App\Http\Controllers\Staff;

use App\Core\Accounts\AccountClassifier;
use App\Core\Accounts\User;
use App\Core\Content\RichText;
use App\Core\Mail\StaffMessage;
use App\Core\Mail\StaffMessageRecipientResolution;
use App\Core\Mail\StaffMessageRecipientResolver;
use App\Core\Search\RecordSearch;
use App\Http\Controllers\Controller;
use App\Jobs\SendStaffMessageJob;
use App\Services\AuditLogger;
use App\Services\AuthorizationProfile;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

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

        $resolution = $this->resolveCounts(
            $request->user(),
            $message->recipient_filter,
            $message->category,
        );

        if ($resolution->recipientCount() === 0) {
            return back()->withErrors(['recipients' => __('staff_messages.errors.no_recipients')]);
        }

        $message->recipient_count = $resolution->recipientCount();
        $message->opted_out_count = $resolution->optedOutCount;

        if ($this->requiresConfirmCount($message)) {
            $request->validate([
                'confirm_count' => ['required', 'integer', 'in:'.$message->recipient_count],
            ]);
        }

        $recipientIds = $resolution->recipients->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all();

        try {
            DB::transaction(function () use ($request, $message, $auditLogger, $resolution, $recipientIds): void {
                $locked = StaffMessage::query()
                    ->whereKey($message->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! in_array($locked->status, [StaffMessage::STATUS_DRAFT, StaffMessage::STATUS_PREVIEW], true)) {
                    abort(403);
                }

                $locked->fill([
                    'status' => StaffMessage::STATUS_QUEUED,
                    'queued_at' => now(),
                    'sent_count' => 0,
                    'failed_count' => 0,
                    'send_offset' => 0,
                    'recipient_count' => $resolution->recipientCount(),
                    'opted_out_count' => $resolution->optedOutCount,
                    'recipient_ids' => $recipientIds,
                ]);
                $locked->save();

                $auditLogger->record(
                    'staff.messages.queued',
                    $request->user(),
                    $locked,
                    [
                        'staff_message_id' => $locked->id,
                        'recipient_count' => $locked->recipient_count,
                        'category' => $locked->category,
                        'subject' => $locked->subject,
                    ],
                );

                try {
                    SendStaffMessageJob::dispatch($locked->id);
                } catch (Throwable $exception) {
                    $locked->update([
                        'status' => StaffMessage::STATUS_PREVIEW,
                        'queued_at' => null,
                        'recipient_ids' => null,
                        'send_offset' => 0,
                    ]);

                    throw $exception;
                }
            });
        } catch (Throwable $exception) {
            if ($exception instanceof HttpException) {
                throw $exception;
            }

            report($exception);

            return back()->withErrors(['recipients' => __('staff_messages.errors.queue_failed')]);
        }

        return redirect()
            ->route('messages.show', $message)
            ->with('status', __('staff_messages.flash.queued'));
    }

    public function userCandidates(Request $request, RecordSearch $search): JsonResponse
    {
        Gate::authorize(AuthorizationProfile::PERMISSION_MESSAGES_SEND);

        $q = trim((string) $request->query('q', ''));

        if (strlen($q) < 2) {
            return response()->json(['candidates' => []]);
        }

        $results = $search->search($request->user(), $q, perType: 15);
        $hits = collect($results['groups'])->firstWhere('type', 'users')['hits'] ?? [];
        $canSendAll = $request->user()->can(AuthorizationProfile::PERMISSION_MESSAGES_SEND_ALL);
        $hitIds = collect($hits)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $allowedIds = $canSendAll
            ? $hitIds
            : $this->resolver->userIdsWithinSenderScope($request->user(), $hitIds, false);

        $candidates = collect($hits)
            ->filter(static fn (array $hit): bool => in_array((int) $hit['id'], $allowedIds, true))
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
            'groups' => $this->availableGroups($user),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function availableGroups(User $user): array
    {
        $groups = [
            AccountClassifier::PUBLIC => __('staff_messages.filters.group_public'),
            AccountClassifier::STUDENT => __('staff_messages.filters.group_student'),
            AccountClassifier::VOLUNTEER => __('staff_messages.filters.group_volunteer'),
        ];

        if ($user->can(AuthorizationProfile::PERMISSION_MESSAGES_SEND_ALL)) {
            $groups[AccountClassifier::STAFF] = __('staff_messages.filters.group_staff');
            $groups[AccountClassifier::ADMIN] = __('staff_messages.filters.group_admin');
        }

        return $groups;
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

    private function resolveCounts(User $sender, array $filter, string $category): StaffMessageRecipientResolution
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
        return $this->profile->isAdministrator($user);
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    private function authorizeFilter(User $user, array $filter): void
    {
        $mode = $filter['mode'] ?? '';
        $canSendAll = $user->can(AuthorizationProfile::PERMISSION_MESSAGES_SEND_ALL);

        if ($mode === 'all' && ! $canSendAll) {
            abort(403);
        }

        if ($mode === 'module'
            && ! $canSendAll
            && ! in_array((string) ($filter['module'] ?? ''), $this->resolver->allowedModuleKeys($user), true)) {
            abort(403);
        }

        if ($mode === 'group' && ! $canSendAll) {
            $group = (string) ($filter['group'] ?? '');
            if (in_array($group, [AccountClassifier::STAFF, AccountClassifier::ADMIN], true)) {
                abort(403);
            }
        }

        if ($mode === 'users' && ! $canSendAll) {
            $requested = array_values(array_unique(array_map(
                static fn ($id): int => (int) $id,
                $filter['user_ids'] ?? [],
            )));
            $allowed = $this->resolver->userIdsWithinSenderScope($user, $requested, false);
            sort($requested);
            $allowedSorted = $allowed;
            sort($allowedSorted);

            if ($requested !== $allowedSorted) {
                abort(403);
            }
        }
    }

    /**
     * @return Builder<StaffMessage>
     */
    private function visibleQuery(User $user): Builder
    {
        $query = StaffMessage::query();

        if ($this->canViewAll($user)) {
            return $query;
        }

        return $query->where('sender_id', $user->id);
    }
}
