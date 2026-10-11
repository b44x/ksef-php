<?php

declare(strict_types=1);

namespace B4x\Ksef\Testing;

use B4x\Ksef\Auth\ContextIdentifier;
use B4x\Ksef\Environment;
use B4x\Ksef\Exception\ApiException;
use B4x\Ksef\Exception\ConfigurationException;
use B4x\Ksef\Http\ApiRequest;
use B4x\Ksef\Http\RetryMode;
use B4x\Ksef\Http\RetryPolicy;
use B4x\Ksef\Http\Transport;
use B4x\Ksef\Permissions\Permission;
use B4x\Ksef\Support\Constraint;
use B4x\Ksef\Support\Nip;
use DateTimeInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The TEST-only data API of KSeF (`/testdata`) for the operations that need no login: set up people, entities with
 * subunits, permissions, the attachment consent and blocked contexts, to build the situation your integration has to
 * handle. (Taxpayers for a quick start: {@see TestEnvironment::createTaxpayer()}. The operations that act on the
 * context you are logged in as, such as limits, are on `KsefClient::testData()`.)
 *
 * It refuses to talk to anything but the TEST environment.
 */
final class TestData
{
    private readonly Transport $transport;

    /**
     * @throws ConfigurationException when the environment is not TEST
     */
    public function __construct(
        ClientInterface $http,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        Environment $environment = Environment::Test,
    ) {
        if ($environment !== Environment::Test) {
            throw new ConfigurationException('The test data API exists on the TEST environment only.');
        }
        $this->transport = new Transport($environment->baseUrl(), $http, $requestFactory, $streamFactory, RetryPolicy::none());
    }

    /**
     * Creates an entity that may have subordinate units: an enforcement authority, a VAT group or a local government unit.
     *
     * @param array<int|string, string> $subunits subordinate units: NIP => description (5 to 256 characters)
     * @param DateTimeInterface|null $createdDate when the same NIP is created again it must be later than the previous date
     *
     * @throws ApiException
     */
    public function createSubject(Nip $nip, TestSubjectType $type, string $description, array $subunits = [], ?DateTimeInterface $createdDate = null): void
    {
        Constraint::length('subject description', $description, 5, 256);
        $units = [];
        foreach ($subunits as $unitNip => $unitDescription) {
            Constraint::length('subunit description', $unitDescription, 5, 256);
            $units[] = ['subjectNip' => (string) $unitNip, 'description' => $unitDescription];
        }
        $this->post('/testdata/subject', array_filter([
            'subjectNip' => $nip->value,
            'subjectType' => $type->value,
            'description' => $description,
            'subunits' => $units === [] ? null : $units,
            'createdDate' => $createdDate?->format(DATE_ATOM),
        ], static fn(mixed $v): bool => $v !== null));
    }

    /** @throws ApiException */
    public function removeSubject(Nip $nip): void
    {
        $this->post('/testdata/subject/remove', ['subjectNip' => $nip->value]);
    }

    /** @throws ApiException */
    public function removePerson(Nip $nip): void
    {
        $this->post('/testdata/person/remove', ['nip' => $nip->value]);
    }

    /**
     * Grants permissions in the context of an entity to a person or another entity, without any login.
     *
     * @param non-empty-list<Permission> $permissions
     * @param string $description 5 to 256 characters, stored with every permission
     *
     * @throws ApiException
     */
    public function grantPermissions(Nip $context, TestIdentifierType $authorizedType, string $authorizedValue, array $permissions, string $description): void
    {
        Constraint::length('permission description', $description, 5, 256);
        $this->post('/testdata/permissions', [
            'contextIdentifier' => ['type' => 'Nip', 'value' => $context->value],
            'authorizedIdentifier' => ['type' => $authorizedType->value, 'value' => $authorizedValue],
            'permissions' => array_map(static fn(Permission $p): array => ['permissionType' => $p->value, 'description' => $description], $permissions),
        ]);
    }

    /** @throws ApiException */
    public function revokePermissions(Nip $context, TestIdentifierType $authorizedType, string $authorizedValue): void
    {
        $this->post('/testdata/permissions/revoke', [
            'contextIdentifier' => ['type' => 'Nip', 'value' => $context->value],
            'authorizedIdentifier' => ['type' => $authorizedType->value, 'value' => $authorizedValue],
        ]);
    }

    /**
     * Gives a taxpayer the consent to send invoices with attachments (see also {@see TestEnvironment::allowAttachments()}).
     *
     * @throws ApiException
     */
    public function allowAttachments(Nip $nip): void
    {
        $this->post('/testdata/attachment', ['nip' => $nip->value]);
    }

    /**
     * Withdraws the attachment consent, optionally from a given date on.
     *
     * @throws ApiException
     */
    public function revokeAttachments(Nip $nip, ?DateTimeInterface $endDate = null): void
    {
        $this->post('/testdata/attachment/revoke', array_filter(['nip' => $nip->value, 'expectedEndDate' => $endDate?->format('Y-m-d')], static fn(?string $v): bool => $v !== null));
    }

    /**
     * Blocks logging in to a context: the way to see how your integration behaves when KSeF refuses an entity.
     *
     * @throws ApiException
     */
    public function blockContext(ContextIdentifier $context): void
    {
        $this->post('/testdata/context/block', ['contextIdentifier' => $context->toArray()]);
    }

    /** @throws ApiException */
    public function unblockContext(ContextIdentifier $context): void
    {
        $this->post('/testdata/context/unblock', ['contextIdentifier' => $context->toArray()]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $path, array $body): void
    {
        $this->transport->send(ApiRequest::post($path, $body, null, RetryMode::Never));
    }
}
