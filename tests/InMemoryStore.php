<?php

declare(strict_types=1);

namespace Duijker\ScimBundle\Tests;

use Duijker\ScimBundle\ScimUser;
use Duijker\ScimBundle\ScimUserStore;

final class InMemoryStore implements ScimUserStore
{
    /** @var array<string, ScimUser> */
    private array $users = [];

    public function find(string $id): ?ScimUser
    {
        return $this->users[$id] ?? null;
    }

    public function search(?string $attribute, ?string $value, int $offset, int $limit): array
    {
        $all = array_values(array_filter(
            $this->users,
            static fn (ScimUser $u) => null === $attribute || 0 === strcasecmp((string) $u->{$attribute}, (string) $value),
        ));

        return [\array_slice($all, $offset, $limit), \count($all)];
    }

    public function save(ScimUser $user): ScimUser
    {
        $user->id ??= bin2hex(random_bytes(8));

        return $this->users[$user->id] = $user;
    }

    public function delete(string $id): bool
    {
        $found = isset($this->users[$id]);
        unset($this->users[$id]);

        return $found;
    }
}
