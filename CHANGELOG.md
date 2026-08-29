# Changelog

## 1.2.1 - 2026-08-29

OMP 3.5 integration hardening and peer-review parity foundation.

### Added

- Native OMP 3.5 repository-backed monograph metadata, contributors and submission-file mapping.
- PKP plugin API controller registered through `APIHandler::endpoints::plugin`.
- Signed role-scoped editor, author and reviewer launch assertions for the OMP profile.
- Assignment-scoped reviewer file access using the PKP `ReviewFilesDAO` authorization boundary.
- Native PKP review-form reading and response persistence for reviewer assignments.
- Signed server-to-server review comment and review-form writeback.
- Binary submission-file transfer with private/no-store response headers.
- Explicit implemented vs planned capability discovery.
- PHP 8.2/8.3/8.4 syntax CI and plugin package integrity checks.
- Hardened Dependency Review workflow.

### Security

- Reviewer assertions do not receive contributor or reviewer identity scopes.
- Reviewer file lists and downloads are constrained to the concrete `ReviewAssignment`.
- Review-form element IDs and values are validated against the form assigned by OMP/PKP.
- Service writeback uses HMAC-SHA256 with installation binding and a bounded timestamp window.

### Deferred until native OMP/PKP workflow paths are verified end-to-end

- Native reviewer recommendation IDs.
- Reviewer revision-file upload/writeback.
- Author revised-file upload/writeback.
- Publication export.

## 1.1.0 - 2026-08-07

Initial OMP 3.5 integration scaffold for `omi-integration/1/omp`.

### Added

- Open Manuscript Studio launch integration foundation.
- HMAC-SHA256 signed short-lived launch assertions.
- Capability discovery foundation.
- OMP 3.5 adapter layer.
- Shared connector-core classes intended to remain compatible with the OJS connector architecture.
- Press, submission and component-aware identity model.
- English, Hungarian and German localization foundation.

### Not yet advertised as complete

- Binary file transfer.
- Revision write-back.
- Full manuscript synchronization.
- Peer-review read/write synchronization.
- Production export.
