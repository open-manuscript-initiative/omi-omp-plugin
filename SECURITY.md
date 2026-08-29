# Security Policy

## Supported versions

Security fixes are provided for the latest released OMP 3.5.x-compatible plugin line. Install the newest release compatible with the deployed OMP version.

## Security model

- OMP is the authorization and workflow authority.
- Studio receives no direct OMP database or private-filesystem credentials.
- Launch assertions are short-lived HMAC-SHA256 signed tokens scoped by actor role, press and submission.
- Reviewer access is bound to a concrete current `ReviewAssignment`.
- Reviewer-visible source files are checked with PKP `ReviewFilesDAO`.
- Review forms and comments are written through PKP review-assignment services.
- Reviewer-returned files and author revisions are persisted using PKP submission-file stages and associations.
- Author revision writes are restricted to the submission's current review stage and current round.
- Completed review assignments reject integration writeback.
- OMP review completion, notifications, event logging and access-invitation finalization remain in the native OMP workflow.

## Secrets

The integration shared secret must be high entropy, stored server-side and never committed to source control, embedded in frontend bundles, exposed in URLs or written to application logs. Production installations must use HTTPS.

If a shared secret is suspected to be exposed, rotate it in OMP and the Studio server before resuming integration traffic.

## Reporting a vulnerability

Please report security issues privately to the Open Manuscript Initiative maintainers before opening a public issue. Include the affected plugin version, OMP version, reproduction steps and the expected/observed authorization boundary.

Do not include live credentials, private manuscript content or reviewer identities in public reports.
