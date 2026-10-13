<?php

declare(strict_types=1);

namespace Duijker\ScimBundle;

/**
 * Supplies the bearer token Entra must send. Called on every request, so it can read from a database or vault.
 * The default reads the scim.token config; alias this interface to your own service to replace it.
 */
interface ScimTokenProvider
{
    public function getToken(): string;
}
