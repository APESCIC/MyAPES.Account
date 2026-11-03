<?php

namespace App\Http\Controllers\Admin;

use App\Core\Extensions\Settings\ManagedListDefinition;
use App\Core\Extensions\Settings\ManagedListRegistry;
use App\Core\Extensions\Settings\ManagedListService;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Admin screen for one managed list (#471): add, rename, reorder, archive,
 * restore and delete rows.
 */
class AdminManagedListController extends Controller
{
    public function __construct(
        private readonly ManagedListRegistry $registry,
        private readonly ManagedListService $lists,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request, string $subCoreKey, string $moduleKey, string $listKey): View
    {
        $list = $this->list($subCoreKey, $moduleKey, $listKey);
        $items = array_map(
            static fn (array $item): array => [...$item, 'in_use' => ($list->usageCount)((string) $item['key'])],
            $this->lists->items($list),
        );

        return view('admin.modules.managed-list', [
            'list' => $list,
            'items' => $items,
            'canManage' => $request->user()?->can('admin.modules.manage') ?? false,
        ]);
    }

    public function store(Request $request, string $subCoreKey, string $moduleKey, string $listKey): RedirectResponse
    {
        $list = $this->list($subCoreKey, $moduleKey, $listKey);
        $label = $request->validate(['label' => ['required', 'string', 'max:255']])['label'];

        return $this->apply($request, $list, 'added', function () use ($list, $label, $request): string {
            return (string) $this->lists->add($list, $label, $request->user())['key'];
        }, 'admin.managed_lists.flash.added');
    }

    public function update(Request $request, string $subCoreKey, string $moduleKey, string $listKey, string $itemKey): RedirectResponse
    {
        $list = $this->list($subCoreKey, $moduleKey, $listKey);
        $label = $request->validate(['label' => ['required', 'string', 'max:255']])['label'];

        return $this->apply($request, $list, 'renamed', function () use ($list, $itemKey, $label, $request): string {
            $this->lists->rename($list, $itemKey, $label, $request->user());

            return $itemKey;
        }, 'admin.managed_lists.flash.renamed');
    }

    public function move(Request $request, string $subCoreKey, string $moduleKey, string $listKey, string $itemKey): RedirectResponse
    {
        $list = $this->list($subCoreKey, $moduleKey, $listKey);
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];

        return $this->apply($request, $list, 'moved', function () use ($list, $itemKey, $direction, $request): string {
            $this->lists->move($list, $itemKey, $direction, $request->user());

            return $itemKey;
        }, 'admin.managed_lists.flash.moved');
    }

    public function destroy(Request $request, string $subCoreKey, string $moduleKey, string $listKey, string $itemKey): RedirectResponse
    {
        $list = $this->list($subCoreKey, $moduleKey, $listKey);
        $outcome = ManagedListService::REMOVED;

        $response = $this->apply($request, $list, 'removed', function () use ($list, $itemKey, $request, &$outcome): string {
            $outcome = $this->lists->remove($list, $itemKey, $request->user());

            return $itemKey;
        }, 'admin.managed_lists.flash.deleted');

        return $outcome === ManagedListService::ARCHIVED
            ? $response->with('status', __('admin.managed_lists.flash.archived'))
            : $response;
    }

    public function restore(Request $request, string $subCoreKey, string $moduleKey, string $listKey, string $itemKey): RedirectResponse
    {
        $list = $this->list($subCoreKey, $moduleKey, $listKey);

        return $this->apply($request, $list, 'restored', function () use ($list, $itemKey, $request): string {
            $this->lists->restore($list, $itemKey, $request->user());

            return $itemKey;
        }, 'admin.managed_lists.flash.restored');
    }

    /**
     * @param  \Closure(): string  $change  returns the affected item key
     */
    private function apply(Request $request, ManagedListDefinition $list, string $action, \Closure $change, string $statusKey): RedirectResponse
    {
        $back = redirect()->route('admin.modules.lists.show', [$list->subCoreKey, $list->moduleKey, $list->listKey]);

        try {
            $itemKey = $change();
        } catch (ValidationException $exception) {
            return $back->withErrors($exception->errors())->withInput();
        }

        $this->audit->record('module.managed_list_'.$action, $request->user(), null, [
            'sub_core_key' => $list->subCoreKey,
            'module_key' => $list->moduleKey,
            'list_key' => $list->listKey,
            'item_key' => $itemKey,
        ]);

        return $back->with('status', __($statusKey));
    }

    private function list(string $subCoreKey, string $moduleKey, string $listKey): ManagedListDefinition
    {
        $list = $this->registry->find($subCoreKey, $moduleKey, $listKey);
        abort_if($list === null, 404);

        return $list;
    }
}
