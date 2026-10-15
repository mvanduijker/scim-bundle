<?php

declare(strict_types=1);

namespace Duijker\ScimBundle;

/**
 * Default until the app implements ScimUserStore. Fails loudly: silently dropping users would look like success in Entra.
 */
final class NullUserStore implements ScimUserStore
{
    public function find(string $id): ?ScimUser
    {
        throw self::error();
    }

    public function search(?string $attribute, ?string $value, int $offset, int $limit): array
    {
        throw self::error();
    }

    public function save(ScimUser $user): ScimUser
    {
        throw self::error();
    }

    public function delete(string $id): bool
    {
        throw self::error();
    }

    private static function error(): ScimError
    {
        return new ScimError(500, 'No ScimUserStore configured: implement '.ScimUserStore::class.' in your app');
    }
}
