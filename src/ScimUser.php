<?php

declare(strict_types=1);

namespace Duijker\ScimBundle;

/**
 * A SCIM user with the commonly used fields pulled out.
 * Anything else (department, phoneNumbers, ...) lives in $attributes, the full SCIM document.
 */
final class ScimUser
{
    public const SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:User';
    public const ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';

    /**
     * @param list<string>         $roles      Entra app role values, e.g. ["Admin", "Reader"]
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public string $userName,
        public ?string $id = null,
        public ?string $externalId = null,
        public bool $active = true,
        public ?string $displayName = null,
        public ?string $givenName = null,
        public ?string $familyName = null,
        public ?string $email = null,
        public array $roles = [],
        public array $attributes = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $userName = $data['userName'] ?? null;
        if (!\is_string($userName) || '' === trim($userName)) {
            throw new ScimError(400, 'userName is required', 'invalidValue');
        }

        // Entra sends "True"/"False" strings unless the tenant URL ends with ?aadOptscim062020
        $active = filter_var($data['active'] ?? true, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);
        if (null === $active) {
            throw new ScimError(400, 'active must be a boolean', 'invalidValue');
        }

        $email = null;
        foreach (\is_array($data['emails'] ?? null) ? $data['emails'] : [] as $e) {
            if (\is_array($e) && (null === $email || filter_var($e['primary'] ?? false, \FILTER_VALIDATE_BOOL))) {
                $email = self::str($e['value'] ?? null);
            }
        }

        $roles = [];
        foreach (\is_array($data['roles'] ?? null) ? $data['roles'] : [] as $r) {
            if (null !== $role = Patch::valueOf($r)) {
                $roles[] = $role;
            }
        }

        $name = \is_array($data['name'] ?? null) ? $data['name'] : [];

        return new self(
            userName: $userName,
            id: self::str($data['id'] ?? null),
            externalId: self::str($data['externalId'] ?? null),
            active: $active,
            displayName: self::str($data['displayName'] ?? null) ?? self::str($name['formatted'] ?? null),
            givenName: self::str($name['givenName'] ?? null),
            familyName: self::str($name['familyName'] ?? null),
            email: $email,
            roles: array_values(array_unique($roles)),
            attributes: $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $doc = $this->attributes;
        unset($doc['password']); // RFC 7643 §4.1.1: never returned
        $doc['schemas'] = isset($doc[self::ENTERPRISE]) ? [self::SCHEMA, self::ENTERPRISE] : [self::SCHEMA];
        $doc['id'] = $this->id;
        $doc['externalId'] = $this->externalId;
        $doc['userName'] = $this->userName;
        $doc['active'] = $this->active;
        $doc['displayName'] = $this->displayName;
        $doc['name'] = array_filter(['givenName' => $this->givenName, 'familyName' => $this->familyName], self::notEmpty(...))
            + (\is_array($doc['name'] ?? null) ? $doc['name'] : []);
        // ponytail: one email; extra emails in $attributes are replaced by this one
        $doc['emails'] = null === $this->email ? [] : [['value' => $this->email, 'type' => 'work', 'primary' => true]];
        $doc['roles'] = array_map(static fn (string $r) => ['value' => $r], $this->roles);
        $doc['meta'] = ['resourceType' => 'User'];

        return array_filter($doc, self::notEmpty(...));
    }

    private static function str(mixed $v): ?string
    {
        return \is_scalar($v) && '' !== (string) $v ? (string) $v : null;
    }

    /** Also drops [], which JSON-encodes as an array where SCIM expects an object */
    private static function notEmpty(mixed $v): bool
    {
        return null !== $v && [] !== $v;
    }
}
