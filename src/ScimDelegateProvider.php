<?php

declare(strict_types=1);

namespace Duijker\ScimBundle;

/**
 * Supplies the apps that receive every change before it is saved here. Called on every request,
 * so it can read from a database. The default reads the scim.delegates config; alias this
 * interface to your own service to replace it.
 */
interface ScimDelegateProvider
{
    /** @return iterable<ScimDelegate> e.g. new ScimDelegate($httpClient, 'https://app2.example.com/scim/v2', $token) */
    public function getDelegates(): iterable;
}
