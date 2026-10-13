<?php

declare(strict_types=1);

namespace Duijker\ScimBundle;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Forwards changes to another app running this bundle, so the customer configures one SCIM app in Entra.
 * Users are matched there by externalId (or userName), since each app has its own ids.
 * Every call is safe to repeat: Entra retries when we answer 502.
 */
final class ScimDelegate
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $url,
        #[\SensitiveParameter] private readonly string $token,
    ) {
    }

    public function create(ScimUser $user): void
    {
        $this->upsert($user, $user);
    }

    /** Forwards Entra's own operations, so the other app doesn't lose attributes this app doesn't store. */
    /** @param array<mixed> $operations */
    public function patch(ScimUser $before, ScimUser $after, array $operations): void
    {
        $id = $this->remoteId($before);
        if (null === $id) {
            // e.g. the other app was added after this user was provisioned
            $this->upsert($after, $after);

            return;
        }
        $this->request('PATCH', "/Users/$id", [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
            'Operations' => $operations,
        ]);
    }

    public function delete(ScimUser $user): void
    {
        if (null !== $id = $this->remoteId($user)) {
            $this->request('DELETE', "/Users/$id");
        }
    }

    private function upsert(ScimUser $lookup, ScimUser $user): void
    {
        $doc = $user->toArray();
        unset($doc['id'], $doc['meta']);
        // toArray() leaves out empty lists, but the merge on the other side must clear them
        $doc += ['roles' => [], 'emails' => []];

        $id = $this->remoteId($lookup);
        null === $id
            ? $this->request('POST', '/Users', $doc)
            : $this->request('PATCH', "/Users/$id", [
                'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
                'Operations' => [['op' => 'replace', 'value' => $doc]],
            ]);
    }

    private function remoteId(ScimUser $user): ?string
    {
        // userName as fallback: the other app may have the user from before externalId was mapped
        $filters = array_filter([
            null !== $user->externalId ? 'externalId eq '.json_encode($user->externalId) : null,
            'userName eq '.json_encode($user->userName),
        ]);
        foreach ($filters as $filter) {
            $id = $this->request('GET', '/Users', query: ['filter' => $filter, 'count' => 1])['Resources'][0]['id'] ?? null;
            if (null !== $id) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param array<mixed>|null         $body
     * @param array<string, int|string> $query
     *
     * @return array<mixed>
     */
    private function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        try {
            $response = $this->http->request($method, rtrim($this->url, '/').$path, [
                'auth_bearer' => $this->token,
                'headers' => ['Content-Type' => 'application/scim+json', 'Accept' => 'application/scim+json'],
                'body' => null === $body ? '' : json_encode($body, \JSON_THROW_ON_ERROR),
                'query' => $query,
                'timeout' => 10,
            ]);
            $status = $response->getStatusCode();
            if ($status >= 300 && !('DELETE' === $method && 404 === $status)) {
                throw new ScimError(502, "Delegated $method failed with HTTP $status");
            }

            return 204 === $status ? [] : $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new ScimError(502, 'Delegated request failed: '.$e->getMessage());
        }
    }
}
