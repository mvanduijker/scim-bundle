<?php

declare(strict_types=1);

namespace Duijker\ScimBundle;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ScimController
{
    private const ERROR = 'urn:ietf:params:scim:api:messages:2.0:Error';
    private const LIST = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';

    public function __construct(
        private readonly ScimUserStore $store,
        private readonly ScimTokenProvider $tokens,
        private readonly ?ScimDelegateProvider $delegates = null,
    ) {
    }

    #[Route('/Users', name: 'scim_users', methods: ['GET', 'POST'])]
    #[Route('/Users/{id}', name: 'scim_user', methods: ['GET', 'PATCH', 'DELETE'])]
    public function users(Request $request, ?string $id = null): Response
    {
        try {
            $this->authenticate($request);

            if (null === $id) {
                return match ($request->getMethod()) {
                    'GET' => $this->list($request),
                    'POST' => $this->create($request),
                    default => throw new ScimError(405, 'Method not allowed'),
                };
            }

            return match ($request->getMethod()) {
                'GET' => $this->json($this->find($id)->toArray()),
                'PATCH' => $this->patch($id, $request),
                'DELETE' => $this->delete($id),
                default => throw new ScimError(405, 'Method not allowed'),
            };
        } catch (ScimError $e) {
            return $this->json(array_filter([
                'schemas' => [self::ERROR],
                'status' => (string) $e->status,
                'scimType' => $e->scimType,
                'detail' => $e->getMessage(),
            ]), $e->status);
        }
    }

    private function list(Request $request): Response
    {
        $attribute = $value = null;
        $filter = trim((string) $request->query->get('filter'));
        if ('' !== $filter) {
            // ponytail: only `userName|externalId eq "x"`, which is all Entra sends for users
            if (!preg_match('/^(userName|externalId)\s+eq\s+("(?:[^"\\\\]|\\\\.)*")$/i', $filter, $m)) {
                throw new ScimError(400, 'Only userName/externalId eq "value" filters are supported', 'invalidFilter');
            }
            $attribute = 'username' === strtolower($m[1]) ? 'userName' : 'externalId';
            $value = json_decode($m[2]) ?? throw new ScimError(400, 'Invalid filter value', 'invalidFilter');
        }
        $start = max(1, $request->query->getInt('startIndex', 1));
        $count = min(1000, max(0, $request->query->getInt('count', 100)));

        [$users, $total] = $this->store->search($attribute, $value, $start - 1, $count);

        return $this->json([
            'schemas' => [self::LIST],
            'totalResults' => $total,
            'startIndex' => $start,
            'itemsPerPage' => \count($users),
            'Resources' => array_map(static fn (ScimUser $u) => $u->toArray(), $users),
        ]);
    }

    private function create(Request $request): Response
    {
        $user = ScimUser::fromArray($this->body($request));
        $user->id = null;
        $this->assertUnique($user);
        foreach ($this->delegates() as $delegate) {
            $delegate->create($user);
        }

        return $this->json($this->store->save($user)->toArray(), 201);
    }

    private function patch(string $id, Request $request): Response
    {
        $user = $this->find($id);
        $operations = $this->body($request)['Operations'] ?? null;
        $patched = ScimUser::fromArray(Patch::apply($user->toArray(), $operations));
        $patched->id = $user->id;
        if (0 !== strcasecmp($patched->userName, $user->userName)) {
            $this->assertUnique($patched);
        }
        foreach ($this->delegates() as $delegate) {
            $delegate->patch($user, $patched, $operations);
        }

        return $this->json($this->store->save($patched)->toArray());
    }

    private function delete(string $id): Response
    {
        $user = $this->find($id);
        foreach ($this->delegates() as $delegate) {
            $delegate->delete($user);
        }
        $this->store->delete($id);

        return new Response(null, 204);
    }

    /** @return iterable<ScimDelegate> */
    private function delegates(): iterable
    {
        return $this->delegates?->getDelegates() ?? [];
    }

    private function find(string $id): ScimUser
    {
        return $this->store->find($id) ?? throw new ScimError(404, 'User not found');
    }

    private function assertUnique(ScimUser $user): void
    {
        [$existing] = $this->store->search('userName', $user->userName, 0, 1);
        if ([] !== $existing && $existing[0]->id !== $user->id) {
            throw new ScimError(409, 'userName already exists', 'uniqueness');
        }
    }

    private function authenticate(Request $request): void
    {
        if (preg_match('/^Bearer\s+(\S+)$/i', (string) $request->headers->get('Authorization'), $m) && hash_equals($this->tokens->getToken(), $m[1])) {
            return;
        }

        throw new ScimError(401, 'Invalid bearer token');
    }

    /** @return array<mixed> */
    private function body(Request $request): array
    {
        try {
            $data = json_decode($request->getContent(), true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ScimError(400, 'Invalid JSON', 'invalidSyntax');
        }

        return \is_array($data) ? $data : throw new ScimError(400, 'Expected a JSON object', 'invalidSyntax');
    }

    /** @param array<mixed> $data */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Content-Type' => 'application/scim+json']);
    }
}
