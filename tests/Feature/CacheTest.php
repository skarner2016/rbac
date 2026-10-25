<?php

declare(strict_types=1);

namespace Skarner\Rbac\Tests\Feature;

use Skarner\Rbac\Tests\TestCase;

final class CacheTest extends TestCase
{
    public function testSameAccessSeesGrantAndRevoke(): void
    {
        $button = $this->createButton('Api:Open');
        $role = $this->createRole('role');
        $this->rbac->roles()->assign($role->id, 7);
        $access = $this->rbac->for(7);

        self::assertFalse($access->can($button->code));

        $this->rbac->roles()->grant($role->id, [$button->code]);
        self::assertTrue($access->can($button->code));

        $this->rbac->roles()->revoke($role->id, [$button->code]);
        self::assertFalse($access->can($button->code));
    }

    public function testRoleStatusChangeInvalidatesCache(): void
    {
        $button = $this->createButton('Api:Open');
        $role = $this->createRole('role');
        $this->rbac->roles()->syncPermissions($role->id, [$button->code]);
        $this->rbac->roles()->assign($role->id, 7);
        $access = $this->rbac->for(7);

        self::assertTrue($access->can($button->code));

        $this->rbac->roles()->update($role->id, ['status' => 0]);

        self::assertFalse($access->can($button->code));
    }

    public function testPermissionStatusChangeInvalidatesCache(): void
    {
        $button = $this->createButton('Api:Open');
        $role = $this->createRole('role');
        $this->rbac->roles()->syncPermissions($role->id, [$button->code]);
        $this->rbac->roles()->assign($role->id, 7);
        $access = $this->rbac->for(7);

        self::assertTrue($access->can($button->code));

        $this->rbac->permissions()->update($button->id, ['status' => 0]);

        self::assertFalse($access->can($button->code));
    }

    public function testPermissionCodeRenameInvalidatesCache(): void
    {
        $button = $this->createButton('Api:Open');
        $role = $this->createRole('role');
        $this->rbac->roles()->syncPermissions($role->id, [$button->code]);
        $this->rbac->roles()->assign($role->id, 7);
        $access = $this->rbac->for(7);

        self::assertTrue($access->can('Api:Open'));

        $this->rbac->permissions()->update($button->id, ['code' => 'Api:Renamed']);

        self::assertFalse($access->can('Api:Open'));
        self::assertTrue($access->can('api:renamed'));
        self::assertSame(['Api:Renamed'], $access->codes());
    }

    public function testManualClearCacheAfterDirectWrite(): void
    {
        $button = $this->createButton('Api:Open');
        $role = $this->createRole('role');
        $this->rbac->roles()->syncPermissions($role->id, [$button->code]);
        $this->rbac->roles()->assign($role->id, 7);
        $access = $this->rbac->for(7);

        self::assertTrue($access->can($button->code));

        $this->pdo->exec('UPDATE rbac_roles SET status = 0 WHERE id = ' . $role->id);

        self::assertTrue($access->can($button->code));

        $this->rbac->clearCache();

        self::assertFalse($access->can($button->code));
    }

    public function testNewAccessSeesChangesWithoutManualCacheHandling(): void
    {
        $button = $this->createButton('Api:Open');
        $role = $this->createRole('role');
        $this->rbac->roles()->assign($role->id, 7);

        self::assertFalse($this->rbac->for(7)->can($button->code));

        $this->rbac->roles()->grant($role->id, [$button->code]);

        self::assertTrue($this->rbac->for(7)->can($button->code));
    }
}
