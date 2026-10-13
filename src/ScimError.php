<?php

declare(strict_types=1);

namespace Duijker\ScimBundle;

/** Rendered as a SCIM error response (RFC 7644 §3.12). */
final class ScimError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $detail,
        public readonly ?string $scimType = null,
    ) {
        parent::__construct($detail);
    }
}
