<?php

declare(strict_types=1);

namespace Duijker\ScimBundle;

/**
 * Implement this in your app to map SCIM users onto your user table.
 */
interface ScimUserStore
{
    public function find(string $id): ?ScimUser;

    /**
     * @param 'userName'|'externalId'|null $attribute null = no filter; compare userName case-insensitively
     *
     * @return array{list<ScimUser>, int} the requested page and the total number of matches
     */
    public function search(?string $attribute, ?string $value, int $offset, int $limit): array;

    /** Create ($user->id === null) or update. Return the stored user, including its id. */
    public function save(ScimUser $user): ScimUser;

    /** Hard delete. Entra soft-deletes first via active=false. Return false when not found. */
    public function delete(string $id): bool;
}
