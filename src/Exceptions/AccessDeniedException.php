<?php

declare(strict_types=1);

namespace Skarner\Rbac\Exceptions;

use Throwable;

/**
 * 鉴权失败。宿主可据此映射 403。
 */
final class AccessDeniedException extends RbacException
{
    /**
     * @param string[] $codes
     */
    public function __construct(
        public readonly string $subjectType,
        public readonly string $subjectId,
        public readonly array $codes,
        string $message = '无权访问。',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
