<?php

declare(strict_types=1);

/**
 * 07 - Who may do what: give a person or another company access, list it, take it away.
 *
 *   php examples/07-permissions.php
 *
 * Permission changes are asynchronous in KSeF; the SDK waits for the outcome and throws
 * PermissionOperationException (with KSeF's status code) if it is refused.
 */

use B4x\Ksef\Exception\PermissionOperationException;
use B4x\Ksef\Permissions\Permission;
use B4x\Ksef\Permissions\PersonSubject;

require __DIR__ . '/bootstrap.php';

$example = example();
$ksef = $example->ksef;

$pesel = '90010112340';   // a fictitious person; on TEST the checksum is not enforced for this demo

step('1. Let an accountant read and issue invoices in your context');
$accountant = PersonSubject::byPesel($pesel, 'Anna', 'Nowak');
$ksef->grantPersonPermissions($accountant, [Permission::InvoiceRead, Permission::InvoiceWrite], 'external accountant');
say('  granted.');

step('2. Who has access now?');
foreach ($ksef->personPermissions()['permissions'] as $grant) {
    say(sprintf('  %-12s %-8s %s (id %s)', $grant->scope, $grant->holderType, $grant->holder, $grant->id));
}

step('3. Take it away again');
foreach ($ksef->personPermissions(grantedByMe: true)['permissions'] as $grant) {
    if ($grant->holder === $pesel) {
        $ksef->revokePermission($grant->id);
        say('  revoked ' . $grant->scope);
    }
}

step('4. What a refusal looks like');
try {
    // Enforcement operations exist only in bailiff/enforcement-authority contexts.
    $ksef->grantPersonPermissions($accountant, [Permission::EnforcementOperations], 'not possible here');
} catch (PermissionOperationException $e) {
    say(sprintf('  KSeF refused (%d): %s', $e->status->code, $e->status->description));
}

step('Another company? Same idea:');
say("  \$ksef->grantEntityPermissions(Nip::of('5265877635'), 'Partner sp. z o.o.', ['InvoiceRead' => true]);");
