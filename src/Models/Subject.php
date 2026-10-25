<?php

declare(strict_types=1);

namespace Skarner\Rbac\Models;

use Skarner\Rbac\Exceptions\RbacException;

/**
 * 授权主体。subject_type 是任意判别值（默认 user），subject_id 支持自增/UUID/雪花 ID。
 */
final readonly class Subject
{
    public function __construct(
        public string $type,
        public string $id,
    ) {
        if ($type === '' || $id === '') {
            throw RbacException::invalidSubject();
        }

        if (strlen($type) > 191 || strlen($id) > 64) {
            throw RbacException::invalidConfig('subject_type 最大 191 字符，subject_id 最大 64 字符。');
        }
    }

    public function key(): string
    {
        return $this->type . "\0" . $this->id;
    }
}
