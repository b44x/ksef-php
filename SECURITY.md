# Security Policy

## Reporting a vulnerability

Please do **not** open a public issue for security problems.

Use GitHub's private vulnerability reporting ("Security" tab, "Report a vulnerability") for this
repository. Include a description, reproduction steps and the affected version. You will receive
an acknowledgement within a few days.

## Scope

This library handles authentication tokens, private keys and invoice data. Reports about
credential leakage (logs, exception messages), unsafe XML parsing, weak cryptographic defaults
or unsafe retry behaviour are in scope.

## Supported versions

Only the latest minor release of the latest major version receives security fixes.
