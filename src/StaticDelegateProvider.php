<?php

declare(strict_types=1);

namespace Duijker\ScimBundle;

final class StaticDelegateProvider implements ScimDelegateProvider
{
    /** @param list<ScimDelegate> $delegates */
    public function __construct(private readonly array $delegates)
    {
    }

    public function getDelegates(): iterable
    {
        return $this->delegates;
    }
}
