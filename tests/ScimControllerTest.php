<?php

declare(strict_types=1);

namespace Duijker\ScimBundle\Tests;

use Duijker\ScimBundle\NullUserStore;
use Duijker\ScimBundle\ScimController;
use Duijker\ScimBundle\ScimDelegate;
use Duijker\ScimBundle\ScimUser;
use Duijker\ScimBundle\StaticDelegateProvider;
use Duijker\ScimBundle\StaticTokenProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

/** Replays the requests Entra sends during a provisioning cycle. */
final class ScimControllerTest extends TestCase
{
    private ScimController $scim;

    protected function setUp(): void
    {
        $this->scim = new ScimController(new InMemoryStore(), new StaticTokenProvider('acme-token'));
    }

    public function testEntraProvisioningCycle(): void
    {
        $this->assertSame(401, $this->call('GET', '/Users', token: 'wrong')[0]);

        // "Test connection"
        [$status, $body] = $this->call('GET', '/Users?filter='.urlencode('userName eq "nobody@acme.com"'));
        $this->assertSame([200, 0], [$status, $body['totalResults']]);

        [$status, $user] = $this->call('POST', '/Users', [
            'schemas' => [ScimUser::SCHEMA, ScimUser::ENTERPRISE],
            'externalId' => '0b1c-entra-object-id',
            'userName' => 'Jane@acme.com',
            'active' => true,
            'displayName' => 'Jane Doe',
            'name' => ['givenName' => 'Jane', 'familyName' => 'Doe'],
            'emails' => [['primary' => true, 'type' => 'work', 'value' => 'jane@acme.com']],
            ScimUser::ENTERPRISE => ['department' => 'Sales'],
            'roles' => [['primary' => false, 'type' => 'WindowsAzureActiveDirectoryRole', 'display' => 'Admin', 'value' => 'Admin']],
            'password' => 'not-echoed',
        ]);
        $this->assertSame(201, $status);
        $this->assertArrayNotHasKey('password', $user);
        $this->assertSame([['value' => 'Admin']], $user['roles']);
        $id = $user['id'];

        $this->assertSame(409, $this->call('POST', '/Users', ['userName' => 'jane@ACME.com'])[0]);

        [, $body] = $this->call('GET', '/Users?filter='.urlencode('externalId eq "0b1c-entra-object-id"'));
        $this->assertSame($id, $body['Resources'][0]['id']);

        [$status, $user] = $this->call('PATCH', "/Users/$id", ['Operations' => [
            ['op' => 'Replace', 'path' => 'name.givenName', 'value' => 'Janet'],
            ['op' => 'Replace', 'path' => 'emails[type eq "work"].value', 'value' => 'janet@acme.com'],
            ['op' => 'Add', 'path' => ScimUser::ENTERPRISE.':employeeNumber', 'value' => '42'],
            ['op' => 'Replace', 'value' => [ScimUser::ENTERPRISE => ['department' => 'Support'], 'displayName' => 'Janet Doe']],
            ['op' => 'Replace', 'path' => 'active', 'value' => 'False'],
            ['op' => 'Add', 'path' => 'roles', 'value' => [['value' => '{"id":"a1","value":"Reader","displayName":"Reader"}']]],
            ['op' => 'Remove', 'path' => 'roles', 'value' => [['value' => '{"id":"a0","value":"Admin","displayName":"Admin"}']]],
        ]], $id);
        $this->assertSame(200, $status);
        $this->assertSame(['Janet', 'Doe'], [$user['name']['givenName'], $user['name']['familyName']]);
        $this->assertSame('janet@acme.com', $user['emails'][0]['value']);
        $this->assertSame(['department' => 'Support', 'employeeNumber' => '42'], $user[ScimUser::ENTERPRISE]);
        $this->assertSame('Janet Doe', $user['displayName']);
        $this->assertFalse($user['active']);
        $this->assertSame([['value' => 'Reader']], $user['roles']);

        $addPhone = ['Operations' => [['op' => 'Add', 'path' => 'phoneNumbers', 'value' => [['type' => 'work', 'value' => '+31 20 1234567']]]]];
        $this->call('PATCH', "/Users/$id", $addPhone, $id);
        [, $user] = $this->call('PATCH', "/Users/$id", $addPhone, $id);
        $this->assertCount(1, $user['phoneNumbers'], 'retried add is idempotent');

        // AssertiveAppRoleAssignmentsComplex sends the full role set
        [, $user] = $this->call('PATCH', "/Users/$id", ['Operations' => [
            ['op' => 'replace', 'path' => 'roles', 'value' => [['primary' => false, 'type' => 'WindowsAzureActiveDirectoryRole', 'display' => 'Viewer', 'value' => 'Viewer']]],
        ]], $id);
        $this->assertSame([['value' => 'Viewer']], $user['roles']);

        $this->assertSame(400, $this->call('PATCH', "/Users/$id", ['Operations' => [['op' => 'Replace', 'path' => 'active', 'value' => 'maybe']]], $id)[0]);
        $this->assertSame(204, $this->call('DELETE', "/Users/$id", id: $id)[0]);
        $this->assertSame(404, $this->call('GET', "/Users/$id", id: $id)[0]);
    }

    public function testUnconfiguredBundleFailsPerRequestNotAtInstall(): void
    {
        $this->scim = new ScimController(new NullUserStore(), new StaticTokenProvider(''));
        $this->assertSame(401, $this->call('GET', '/Users')[0], 'no token = SCIM off');

        $this->scim = new ScimController(new NullUserStore(), new StaticTokenProvider('acme-token'));
        [$status, $body] = $this->call('GET', '/Users');
        $this->assertSame(500, $status);
        $this->assertStringContainsString('ScimUserStore', $body['detail']);
    }

    public function testDelegatesEveryChangeToSecondApp(): void
    {
        $app2 = new InMemoryStore();
        $app2Scim = new ScimController($app2, new StaticTokenProvider('app2-token'));
        $up = true;
        // Route the delegate's HTTP calls into a second app running this bundle
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($app2Scim, &$up) {
            if (!$up) { // @phpstan-ignore booleanNot.alwaysFalse (PHPStan doesn't see by-ref closure writes)
                return new MockResponse('', ['http_code' => 503]);
            }
            $id = preg_match('#/Users/([^/?]+)#', $url, $m) ? $m[1] : null;
            $request = Request::create($url, $method, content: $options['body'] ?? '');
            // normalized header line is "Authorization: Bearer ..."
            $request->headers->set('Authorization', substr($options['normalized_headers']['authorization'][0], \strlen('Authorization: ')));
            $response = $app2Scim->users($request, $id);

            return new MockResponse((string) $response->getContent(), ['http_code' => $response->getStatusCode()]);
        });
        $this->scim = new ScimController(new InMemoryStore(), new StaticTokenProvider('acme-token'), new StaticDelegateProvider([
            new ScimDelegate($http, 'https://app2.example.com/scim/v2', 'app2-token'),
        ]));

        // app 2 already had this user from before externalId was mapped, with a role Entra no longer assigns
        $app2->save(new ScimUser('legacy@acme.com', roles: ['Admin']));
        $this->assertSame(201, $this->call('POST', '/Users', ['externalId' => 'oid-0', 'userName' => 'legacy@acme.com'])[0]);
        $legacy = $app2->search('userName', 'legacy@acme.com', 0, 10);
        $this->assertSame([1, 'oid-0', []], [$legacy[1], $legacy[0][0]->externalId, $legacy[0][0]->roles]);

        [, $user] = $this->call('POST', '/Users', [
            'externalId' => 'oid-1', 'userName' => 'jane@acme.com',
            ScimUser::ENTERPRISE => ['department' => 'Sales'],
            'roles' => [['value' => 'Admin']],
        ]);
        $id = $user['id'];
        $remote = $app2->search('externalId', 'oid-1', 0, 1)[0][0];
        $remoteId = $remote->id ?? self::fail('app 2 saved the user without an id');
        $this->assertNotSame($id, $remoteId, 'apps have their own ids');
        $this->assertSame(['Admin'], $remote->roles);

        // Rename + role change: forwarded as Entra's operations, matched by externalId
        $this->call('PATCH', "/Users/$id", ['Operations' => [
            ['op' => 'Replace', 'path' => 'userName', 'value' => 'janet@acme.com'],
            ['op' => 'Add', 'path' => 'roles', 'value' => [['value' => '{"id":"a1","value":"Reader"}']]],
        ]], $id);
        $remote = $app2->find($remoteId) ?? self::fail('user gone from app 2');
        $this->assertSame(['janet@acme.com', ['Admin', 'Reader'], 'Sales'], [$remote->userName, $remote->roles, $remote->attributes[ScimUser::ENTERPRISE]['department']]);

        // App 2 down: Entra gets 502 and nothing is saved here, so its retry keeps both apps in sync
        $up = false;
        $patch = ['Operations' => [['op' => 'Replace', 'path' => 'active', 'value' => false]]];
        $this->assertSame(502, $this->call('PATCH', "/Users/$id", $patch, $id)[0]);
        $this->assertTrue($this->call('GET', "/Users/$id", id: $id)[1]['active']);
        $up = true;
        $this->assertSame(200, $this->call('PATCH', "/Users/$id", $patch, $id)[0]);
        $this->assertFalse($app2->find($remoteId)->active);

        $this->assertSame(204, $this->call('DELETE', "/Users/$id", id: $id)[0]);
        $this->assertNull($app2->find($remoteId));
    }

    /**
     * @param array<mixed>|null $body
     *
     * @return array{int, array<mixed>}
     */
    private function call(string $method, string $uri, ?array $body = null, ?string $id = null, string $token = 'acme-token'): array
    {
        $request = Request::create($uri, $method, content: null === $body ? '' : json_encode($body, \JSON_THROW_ON_ERROR));
        $request->headers->set('Authorization', "Bearer $token");
        $response = $this->scim->users($request, $id);

        return [$response->getStatusCode(), json_decode($response->getContent() ?: 'null', true)];
    }
}
