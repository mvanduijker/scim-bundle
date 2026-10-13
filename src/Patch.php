<?php

declare(strict_types=1);

namespace Duijker\ScimBundle;

/**
 * Applies SCIM PATCH operations (RFC 7644 §3.5.2) to a resource array.
 * Supports the path shapes Entra sends: attr, attr.sub, attr[x eq "y"], attr[x eq "y"].sub,
 * each optionally prefixed with a schema URN (urn:...:User:department).
 */
final class Patch
{
    private const PATH = '/^(?:(urn:.+:(?:User|Group)):)?(\w+)(?:\[(\w+)\s+eq\s+"([^"]*)"\])?(?:\.(\w+))?$/i';

    /**
     * @param array<string, mixed> $doc
     *
     * @return array<string, mixed>
     */
    public static function apply(array $doc, mixed $operations): array
    {
        if (!\is_array($operations)) {
            throw new ScimError(400, 'Operations must be an array', 'invalidSyntax');
        }

        foreach ($operations as $op) {
            $type = \is_array($op) && \is_string($op['op'] ?? null) ? strtolower($op['op']) : '';
            if (!\in_array($type, ['add', 'replace', 'remove'], true)) {
                throw new ScimError(400, 'Unsupported patch op', 'invalidSyntax');
            }
            $path = $op['path'] ?? '';
            $value = $op['value'] ?? null;

            if (\is_string($path) && '' !== $path) {
                $doc = self::applyPath($doc, $type, $path, $value);
                continue;
            }
            if ('remove' === $type || !\is_array($value)) {
                throw new ScimError(400, 'An operation without path needs an object value', 'noTarget');
            }
            foreach ($value as $key => $v) {
                // {"urn:...:enterprise:2.0:User": {"department": "x"}} holds attributes of that schema
                if (\is_array($v) && preg_match('/^urn:.+:(User|Group)$/i', (string) $key)) {
                    foreach ($v as $sub => $subValue) {
                        $doc = self::applyPath($doc, $type, "$key:$sub", $subValue);
                    }
                } else {
                    $doc = self::applyPath($doc, $type, (string) $key, $v);
                }
            }
        }

        return $doc;
    }

    /**
     * @param array<string, mixed> $doc
     *
     * @return array<string, mixed>
     */
    private static function applyPath(array $doc, string $type, string $path, mixed $value): array
    {
        if (!preg_match(self::PATH, $path, $m)) {
            throw new ScimError(400, "Unsupported path: $path", 'invalidPath');
        }
        [, $schema, $attr, $filterAttr, $filterValue, $sub] = array_pad($m, 6, '');

        $c = &$doc;
        if ('' !== $schema && 0 !== strcasecmp($schema, ScimUser::SCHEMA)) {
            $c = &$doc[self::key($doc, $schema)];
            $c ??= [];
        }
        if (!\is_array($c)) {
            throw new ScimError(400, "Invalid target: $path", 'invalidPath');
        }
        $attr = self::key($c, $attr);

        if ('' === $filterAttr && '' === $sub) {
            if ('remove' === $type && \is_array($value) && array_is_list($value) && \is_array($c[$attr] ?? null)) {
                // Entra removes roles by value, not by filter: {"op":"Remove","path":"roles","value":[{"value":"..."}]}
                $drop = array_map(self::valueOf(...), $value);
                $c[$attr] = array_values(array_filter($c[$attr], static fn ($item) => !\in_array(self::valueOf($item), $drop, true)));
            } elseif ('remove' === $type) {
                unset($c[$attr]);
            } else {
                $c[$attr] = self::merge($c[$attr] ?? null, $value, $type);
            }
        } elseif ('' === $filterAttr) {
            $c[$attr] ??= [];
            // ponytail: attr.sub on a multi-valued attribute (phoneNumbers.value) is rejected, Entra always filters
            if (!\is_array($c[$attr]) || ([] !== $c[$attr] && array_is_list($c[$attr]))) {
                throw new ScimError(400, "Invalid target: $path", 'invalidPath');
            }
            $sub = self::key($c[$attr], $sub);
            if ('remove' === $type) {
                unset($c[$attr][$sub]);
            } else {
                $c[$attr][$sub] = self::merge($c[$attr][$sub] ?? null, $value, $type);
            }
        } else {
            $list = $c[$attr] ?? [];
            if (!\is_array($list) || !array_is_list($list)) {
                throw new ScimError(400, "Invalid target: $path", 'invalidPath');
            }
            $found = false;
            foreach ($list as $i => $item) {
                $match = \is_array($item) ? ($item[self::key($item, $filterAttr)] ?? null) : null;
                if (!\is_scalar($match) || 0 !== strcasecmp((string) $match, $filterValue)) {
                    continue;
                }
                $found = true;
                if ('remove' === $type && '' === $sub) {
                    unset($list[$i]);
                } elseif ('remove' === $type) {
                    unset($list[$i][self::key($item, $sub)]);
                } elseif ('' === $sub) {
                    $list[$i] = self::merge($item, $value, $type);
                } else {
                    $list[$i][self::key($item, $sub)] = $value;
                }
            }
            // emails[type eq "work"].value on a user without a work email creates it
            if (!$found && 'remove' !== $type) {
                $list[] = '' === $sub ? self::merge([$filterAttr => $filterValue], $value, $type) : [$filterAttr => $filterValue, $sub => $value];
            }
            $c[$attr] = array_values($list);
        }
        unset($c);

        return $doc;
    }

    /** Complex values merge sub-attributes, "add" appends to multi-valued, everything else replaces. */
    private static function merge(mixed $old, mixed $new, string $type): mixed
    {
        if (\is_array($old) && \is_array($new)) {
            if (array_is_list($old) && array_is_list($new)) {
                if ('replace' === $type) {
                    return $new;
                }
                // existing values are left alone (RFC 7644 §3.5.2.1), so a retried add doesn't duplicate
                foreach ($new as $item) {
                    if (!\in_array($item, $old)) {
                        $old[] = $item;
                    }
                }

                return $old;
            }
            if (!array_is_list($old) && !array_is_list($new)) {
                return array_replace($old, $new);
            }
        }

        return $new;
    }

    /**
     * The "value" of a multi-valued item. Entra sends role items in PATCH as JSON strings:
     * {"value": "{\"id\":\"...\",\"value\":\"Admin\",\"displayName\":\"Admin\"}"}.
     */
    public static function valueOf(mixed $item): ?string
    {
        $v = \is_array($item) ? ($item[self::key($item, 'value')] ?? null) : $item;
        if (\is_string($v) && str_starts_with($v, '{')) {
            $decoded = json_decode($v, true);
            $v = \is_array($decoded) ? ($decoded['value'] ?? null) : $v;
        }

        return \is_scalar($v) && '' !== (string) $v ? (string) $v : null;
    }

    /**
     * SCIM attribute names are case-insensitive: reuse the existing key's casing.
     *
     * @param array<mixed> $a
     */
    private static function key(array $a, string $name): string
    {
        foreach ($a as $k => $_) {
            if (0 === strcasecmp((string) $k, $name)) {
                return (string) $k;
            }
        }

        return $name;
    }
}
