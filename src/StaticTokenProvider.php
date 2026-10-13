<?php

declare(strict_types=1);

namespace Duijker\ScimBundle;

final class StaticTokenProvider implements ScimTokenProvider
{
    public function __construct(#[\SensitiveParameter] private readonly string $token)
    {
    }

    public function getToken(): string
    {
        return $this->token;
    }
}
