<?php

declare(strict_types=1);

namespace Skarner\Rbac\Services;

use Skarner\Rbac\Config;
use Skarner\Rbac\Models\Permission;
use Skarner\Rbac\Models\Subject;

/**
 * 前端菜单树构建器。
 *
 * 从主体已授予权限出发做祖先补全：授权的 route/button 会把整条父链上的启用菜单
 * 一并补出来；父菜单不可见的节点直接丢弃，不提升为根，与宿主既有行为一致。
 */
final class MenuTree
{
    public function __construct(
        private readonly GrantResolver $resolver,
        private readonly Config $config,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function build(Subject $subject): array
    {
        $grants = $this->resolver->resolve($subject);
        $all = $this->resolver->allPermissions();

        $visible = [];

        foreach ($grants->permissionList() as $permission) {
            $this->collectVisible($permission, $all, $visible);
        }

        $codes = [];

        if ($this->config->includePermissionCodes()) {
            foreach ($grants->permissionList() as $permission) {
                if (! $permission->isRoute() && ! $permission->isButton()) {
                    continue;
                }

                $this->collectPermissionCodes($permission, $all, $visible, $codes);
            }
        }

        $roots = [];
        $children = [];

        foreach ($visible as $id => $node) {
            if ($node->parentId === null) {
                $roots[$id] = $node;

                continue;
            }

            if (isset($visible[$node->parentId])) {
                $children[$node->parentId][$id] = $node;
            }
        }

        $roots = $this->sortNodes($roots);

        foreach (array_keys($children) as $parentId) {
            $children[$parentId] = $this->sortNodes($children[$parentId]);
        }

        $tree = [];

        foreach ($roots as $root) {
            $tree[] = $this->node($root, $children, $codes);
        }

        return $tree;
    }

    /**
     * 从给定权限开始沿父链向上遍历，收集启用菜单节点（含授权节点自身）。
     *
     * @param array<int, Permission> $all
     * @param array<int, Permission> $visible
     */
    private function collectVisible(Permission $permission, array $all, array &$visible): void
    {
        $node = $permission;
        $seen = [];

        while ($node !== null) {
            if (isset($seen[$node->id])) {
                break;
            }

            $seen[$node->id] = true;

            if ($node->isMenu() && $node->isEnabled()) {
                $visible[$node->id] = $node;
            }

            $node = $node->parentId === null ? null : ($all[$node->parentId] ?? null);
        }
    }

    /**
     * 把授予的 route/button 权限码挂到其全部可见菜单祖先上。
     *
     * 大小写不敏感去重，值保留数据库原始大小写；顺序跟随已授予权限顺序。
     *
     * @param array<int, Permission> $all
     * @param array<int, Permission> $visible
     * @param array<int, array<string, string>> $codes
     */
    private function collectPermissionCodes(
        Permission $permission,
        array $all,
        array $visible,
        array &$codes,
    ): void {
        $node = $permission->parentId === null ? null : ($all[$permission->parentId] ?? null);
        $seen = [];

        while ($node !== null) {
            if (isset($seen[$node->id])) {
                break;
            }

            $seen[$node->id] = true;

            if (isset($visible[$node->id])) {
                $codes[$node->id][strtolower($permission->code)] = $permission->code;
            }

            $node = $node->parentId === null ? null : ($all[$node->parentId] ?? null);
        }
    }

    /**
     * 按 sort ASC, id ASC 排序，保留 id 键。
     *
     * @param array<int, Permission> $nodes
     * @return array<int, Permission>
     */
    private function sortNodes(array $nodes): array
    {
        uasort(
            $nodes,
            static function (Permission $a, Permission $b): int {
                $bySort = $a->sort <=> $b->sort;

                return $bySort !== 0 ? $bySort : $a->id <=> $b->id;
            },
        );

        return $nodes;
    }

    /**
     * 输出单个菜单节点。
     *
     * @param array<int, array<int, Permission>> $children
     * @param array<int, array<string, string>> $codes
     * @return array<string, mixed>
     */
    private function node(Permission $permission, array $children, array $codes): array
    {
        $items = [];

        foreach ($children[$permission->id] ?? [] as $child) {
            $items[] = $this->node($child, $children, $codes);
        }

        return [
            'id' => (string) $permission->id,
            'parentId' => $permission->parentId === null ? null : (string) $permission->parentId,
            'type' => 'menu',
            'code' => $permission->code,
            'name' => $permission->name,
            'path' => $permission->path,
            'component' => $permission->component,
            'redirect' => $permission->redirect,
            'icon' => $permission->icon,
            'sort' => $permission->sort,
            'hidden' => $permission->hidden,
            'meta' => $permission->meta,
            'permissions' => isset($codes[$permission->id]) ? array_values($codes[$permission->id]) : [],
            'children' => $items,
        ];
    }
}
