# Security Policy

## Supported versions

Security fixes are provided for the latest released OMP 3.5.x-compatible plugin line. Install the newest release compatible with the deployed OMP version.

## Security model

- OMP is the authorization and workflow authority.
- Studio receives no direct OMP database or private-filesystem credentials.
- Launch assertions are short-lived HMAC-SHA256 signed tokens scoped by actor role, press and submission.
- Reviewer access is bound to a concrete current `ReviewAssignment`.
- Reviewer-visible source files are checked with PKP `ReviewFilesDAO`.
- Author-mode file lists and downloads are limited to PKP file stages derived from the signed author's native workflow assignments; non-author roles on the same account are stripped before resolving file visibility.
- Review forms and comments are written through PKP review-assignment services.
- Reviewer-returned files and author revisions are persisted using PKP submission-file stages and associations.
- Author revision writes are restricted to the submission's current review stage and current round.
- Completed review assignments reject integration writeback.
- OMP review completion, notifications, event logging and access-invitation finalization remain in the native OMP workflow.

## Studio service writeback

Service writeback requests are authenticated with the installation identifier,
a bounded Unix timestamp and an HMAC-SHA256 signature over the method, request
path and exact request-body digest. OMP then re-resolves all supplied
submission, actor, review-assignment and review-round identifiers before any
write. A valid service signature is not itself a workflow authorization grant.

Reviewer writes are limited to the current assignment and latest round. Author
revision writes require an author workflow assignment plus a native OMP
revision-request decision in the latest review round. Studio never marks an OMP
review complete.

## Secrets

The integration shared secret must be high entropy, stored server-side and never committed to source control, embedded in frontend bundles, exposed in URLs or written to application logs. Production installations must use HTTPS.

If a shared secret is suspected to be exposed, rotate it in OMP and the Studio server before resuming integration traffic.

## Reporting a vulnerability

Please report security issues privately to the Open Manuscript Initiative maintainers before opening a public issue. Include the affected plugin version, OMP version, reproduction steps and the expected/observed authorization boundary.

Do not include live credentials, private manuscript content or reviewer identities in public reports.
