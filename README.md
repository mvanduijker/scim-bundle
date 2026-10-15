# ScimBundle

A minimal SCIM 2.0 `/Users` endpoint for Microsoft Entra provisioning.

```bash
composer require mvanduijker/scim-bundle
```

## Install

```php
// config/bundles.php
Duijker\ScimBundle\ScimBundle::class => ['all' => true],
```

```yaml
# config/packages/scim.yaml
scim:
    token: '%env(SCIM_TOKEN)%'      # per deployment: bin/console secrets:set SCIM_TOKEN --random=32

# config/routes/scim.yaml
scim:
    resource: Duijker\ScimBundle\ScimController
    type: attribute
    prefix: /scim/v2

# config/packages/security.yaml — put this BEFORE your Entra login firewall; the bundle checks the bearer token itself
security:
    firewalls:
        scim:
            pattern: ^/scim/
            security: false
```

### Token from somewhere else

Leave out `scim.token` and implement `ScimTokenProvider`, e.g. to let an admin set the token in your app.
Without either, the token is empty and every request gets a 401:

```php
#[AsAlias(ScimTokenProvider::class)]
final class DbScimTokenProvider implements ScimTokenProvider
{
    public function __construct(private SettingsRepository $settings) {}

    public function getToken(): string
    {
        return $this->settings->get('scim_token') ?? ''; // empty never matches, so SCIM stays off until it's set
    }
}
```

### Customers with both apps

Install the bundle in both apps. Entra talks only to the main app, which forwards every change to the second one:

```yaml
# main app deployment, only for customers who also have app 2
scim:
    token: '%env(SCIM_TOKEN)%'
    delegates:
        - { url: 'https://app2.customer.example.com/scim/v2', token: '%env(SCIM_APP2_TOKEN)%' }
```

`SCIM_APP2_TOKEN` is app 2's own `SCIM_TOKEN`. To manage this in your app instead, leave out `delegates` and implement
`ScimDelegateProvider`; return an empty list for customers without app 2:

```php
#[AsAlias(ScimDelegateProvider::class)]
final class DbScimDelegateProvider implements ScimDelegateProvider
{
    public function __construct(private HttpClientInterface $http, private SettingsRepository $settings) {}

    public function getDelegates(): iterable
    {
        foreach ($this->settings->scimDelegates() as ['url' => $url, 'token' => $token]) {
            yield new ScimDelegate($this->http, $url, $token);
        }
    }
}
```

 App 2 needs no delegate config, and must never delegate back to the main app,
because that loops.

- Each app keeps its own ids. App 2 matches users by `externalId`, or `userName` without one, so map `objectId` → `externalId` (see below).
- The change is forwarded *before* it's saved locally. If app 2 is down, Entra gets a 502 and retries later, so the apps stay in sync.
- PATCHes are forwarded as Entra's operations, so app 2 also gets attributes the main app doesn't store.
- All users and roles from the one Entra app go to both apps. Use distinct role values (`Admin`, `App2Admin`) and let each app's allowlist pick its own.

## Map it onto your user table

Implement `ScimUserStore` and mark it with `#[AsAlias]` (the bundle ships a `NullUserStore` that answers every request
with a 500 until you do; Symfony doesn't auto-alias interfaces from `vendor/`). Example for a
Doctrine `User` entity (rename the fields to match yours):

```php
namespace App\Scim;

use App\Entity\User;
use Duijker\ScimBundle\{ScimUser, ScimUserStore};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ScimUserStore::class)]
final class UserScimStore implements ScimUserStore
{
    // Entra app role value => app role. Customers choose the role values in their own tenant,
    // so only map known values; never copy them into User::$roles as-is.
    private const ROLES = ['Admin' => 'ROLE_ADMIN', 'User' => 'ROLE_USER'];

    public function __construct(private EntityManagerInterface $em) {}

    public function find(string $id): ?ScimUser
    {
        $user = $this->em->find(User::class, $id);

        return $user ? $this->toScim($user) : null;
    }

    public function search(?string $attribute, ?string $value, int $offset, int $limit): array
    {
        $qb = $this->em->getRepository(User::class)->createQueryBuilder('u');
        match ($attribute) {
            'userName' => $qb->andWhere('LOWER(u.email) = LOWER(:v)')->setParameter('v', $value),
            'externalId' => $qb->andWhere('u.entraObjectId = :v')->setParameter('v', $value),
            null => null,
        };
        $total = (int) (clone $qb)->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();
        $users = $qb->setFirstResult($offset)->setMaxResults($limit)->getQuery()->getResult();

        return [array_map($this->toScim(...), $users), $total];
    }

    public function save(ScimUser $scim): ScimUser
    {
        $user = $scim->id
            ? $this->em->find(User::class, $scim->id)
            : new User();

        $user->setEmail($scim->userName);
        $user->setEntraObjectId($scim->externalId);
        $user->setFirstName($scim->givenName);
        $user->setLastName($scim->familyName);
        $user->setActive($scim->active);
        $user->setRoles(array_values(array_intersect_key(self::ROLES, array_flip($scim->roles))));
        // Unmapped fields live in $scim->attributes, e.g.:
        // $user->setDepartment($scim->attributes[ScimUser::ENTERPRISE]['department'] ?? null);

        $this->em->persist($user);
        $this->em->flush();

        return $this->toScim($user);
    }

    public function delete(string $id): bool
    {
        $user = $this->em->find(User::class, $id);
        if (!$user) {
            return false;
        }
        $this->em->remove($user); // or anonymise / soft-delete if other rows reference the user
        $this->em->flush();

        return true;
    }

    private function toScim(User $u): ScimUser
    {
        return new ScimUser(
            userName: $u->getEmail(),
            id: (string) $u->getId(),
            externalId: $u->getEntraObjectId(),
            active: $u->isActive(),
            givenName: $u->getFirstName(),
            familyName: $u->getLastName(),
            email: $u->getEmail(),
            roles: array_keys(array_intersect(self::ROLES, $u->getRoles())),
        );
    }
}
```

Users with `active = false` must not be able to log in. Check that in your Entra login authenticator or a user checker.

## Customer setup in Entra

Needs Entra ID P1 or P2 and an Application Administrator (or higher) role.

1. Enterprise applications → *New application* → *Create your own application* → "Integrate any other application you
   don't find in the gallery (Non-gallery)". Use a **separate app** for provisioning: an enterprise app created from your
   OIDC login's app registration usually has *Get started* greyed out on the Provisioning page.
2. Provisioning → Mode **Automatic**, under *Admin Credentials*:
   - Tenant URL: `https://<your-host>/scim/v2?aadOptscim062020` (the flag turns on RFC-compliant PATCH; it's not a secret)
   - Secret Token: that deployment's `SCIM_TOKEN`
   - *Test connection*
3. Mappings: **disable "Provision Microsoft Entra ID Groups"**, because this bundle only does users.
4. "Provision Microsoft Entra ID Users": Entra doesn't send `externalId` unless you map it. Edit the `externalId` row
   (or *Add New Mapping*): Direct, source `objectId`, target `externalId`, *Match objects using this attribute* Yes,
   matching precedence **1**, apply Always. Set `userPrincipalName → userName` to precedence 2. Entra then matches on the
   object ID (the `oid` claim) an Entra login stores, so existing users are linked, not duplicated.
5. Roles:
   - In the **provisioning app's** registration (App registrations → All applications), create app roles whose *Value*
     exactly matches a key of your role map (`Admin`, `User`). Values not in the map are dropped.
   - Tick *Show advanced options* → *Edit attribute list for customappsso* and make sure there's a `roles` attribute,
     type String, **Multi-Value** ticked.
   - *Add New Mapping* → Expression `AssertiveAppRoleAssignmentsComplex([appRoleAssignments])` → target `roles`, apply
     Always. Use the *Assertive* variant: the plain one never sends role removals.
   - Assign users or groups to the app with one of your roles. The built-in "User" role has no Value, so Entra sends its
     id (`18d14569-c3bd-439b-9a66-3a2aee01d14f`); your role map drops it.
6. Settings → Scope: "Sync only assigned users and groups".
7. Test one user with **Provision on demand**: it shows the request Entra sends and your app's response. Then turn
   provisioning **On**; after the first cycle Entra syncs changes about every 40 minutes.

### Testing locally

Entra needs a public HTTPS URL; a Cloudflare quick tunnel works without an account:

```bash
cloudflared tunnel --url https://127.0.0.1:8000 --no-tls-verify
# behind nginx-proxy (503 otherwise): also pass --http-host-header <your VIRTUAL_HOST>
```

Use the printed `https://….trycloudflare.com/scim/v2?aadOptscim062020` as Tenant URL. It changes on every start, and
the tunnel exposes your whole dev app (including `/_profiler`), so only run it while testing.

## Not supported

`/Groups`, `/Schemas`, `/ServiceProviderConfig`, `PUT`, and filters other than `userName|externalId eq "…"`.
Entra's user provisioning doesn't need any of these.
