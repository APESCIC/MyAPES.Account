<?php

namespace App\Support\Navigation;

/**
 * Navigation resolved for one user and request (#384): the panels they may
 * use, the current panel, group and item, and the command palette entries.
 */
final readonly class WorkspaceMenu
{
    /**
     * @param  array<string, NavPanel>  $panels  keyed by NavPanel::STAFF / ADMIN / PUBLIC
     * @param  list<QuickAction>  $quickActions
     * @param  list<array{id: string, label: string, section: string, url: string, icon: string, keywords: string}>  $extraCommands
     */
    public function __construct(
        public array $panels,
        public string $currentPanelKey,
        public ?string $activeItemKey,
        public array $quickActions = [],
        public array $extraCommands = [],
        public ?DockUser $user = null,
    ) {}

    public function panel(string $key): ?NavPanel
    {
        return $this->panels[$key] ?? null;
    }

    public function currentPanel(): ?NavPanel
    {
        return $this->panel($this->currentPanelKey);
    }

    /**
     * The Staff | Admin switch only appears when the user can use both.
     */
    public function showsPanelSwitch(): bool
    {
        return isset($this->panels[NavPanel::STAFF], $this->panels[NavPanel::ADMIN]);
    }

    /**
     * @return list<NavPanel>
     */
    public function switchablePanels(): array
    {
        if (! $this->showsPanelSwitch()) {
            return [];
        }

        return [$this->panels[NavPanel::STAFF], $this->panels[NavPanel::ADMIN]];
    }

    public function activeItem(): ?NavItem
    {
        if ($this->activeItemKey === null) {
            return null;
        }

        foreach ($this->currentPanel()?->items() ?? [] as $item) {
            if ($item->key === $this->activeItemKey) {
                return $item;
            }
        }

        return null;
    }

    public function activeGroup(): ?NavGroup
    {
        foreach ($this->currentPanel()?->groups ?? [] as $group) {
            foreach ($group->items as $item) {
                if ($item->key === $this->activeItemKey) {
                    return $group;
                }
            }
        }

        return null;
    }

    public function isActive(NavItem $item): bool
    {
        return $this->activeItemKey !== null && $item->key === $this->activeItemKey;
    }

    /**
     * Panel › Group › Item › Page. The group is dropped when it repeats the
     * item, and the page crumb only appears when it differs from the item.
     *
     * @return list<array{label: string, url: ?string}>
     */
    public function breadcrumb(?string $pageTitle = null): array
    {
        $panel = $this->currentPanel();
        if ($panel === null) {
            return [];
        }

        $crumbs = [['label' => $panel->label, 'url' => $panel->home()?->url]];
        $group = $this->activeGroup();
        $item = $this->activeItem();

        if ($group !== null && $item !== null && $group->heading !== $item->label) {
            $crumbs[] = ['label' => $group->heading, 'url' => $group->items[0]->url];
        }

        if ($item !== null) {
            $crumbs[] = ['label' => $item->label, 'url' => $item->url];
        }

        $pageTitle = trim((string) $pageTitle);
        if ($pageTitle !== '' && $pageTitle !== ($item?->label ?? '')) {
            $crumbs[] = ['label' => $pageTitle, 'url' => null];
        }

        $last = array_key_last($crumbs);
        $crumbs[$last]['url'] = null;

        return $crumbs;
    }

    /**
     * Everything the palette may jump to: quick actions first, then every
     * permitted nav item, then extra entries such as plugin settings.
     *
     * @return list<array{id: string, label: string, section: string, url: string, icon: string, keywords: string}>
     */
    public function paletteCommands(): array
    {
        $commands = [];
        $quickSection = __('workspace.palette.quick_actions');

        foreach ($this->quickActions as $action) {
            $commands[] = [
                'id' => 'action:'.$action->key,
                'label' => $action->label,
                'section' => $action->context === '' ? $quickSection : $quickSection.' · '.$action->context,
                'url' => $action->url,
                'icon' => $action->icon,
                'keywords' => implode(' ', $action->keywords),
            ];
        }

        foreach ($this->panels as $panel) {
            foreach ($panel->groups as $group) {
                foreach ($group->items as $item) {
                    $commands[] = [
                        'id' => $panel->key.':'.$item->key,
                        'label' => $item->label,
                        'section' => $panel->label.' · '.$group->heading,
                        'url' => $item->url,
                        'icon' => $item->icon,
                        'keywords' => implode(' ', [$panel->label, $group->heading, ...$item->keywords]),
                    ];
                }
            }
        }

        return [...$commands, ...$this->extraCommands];
    }
}
