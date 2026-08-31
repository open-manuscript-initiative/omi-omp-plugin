# Changelog

## 1.2.4 - 2026-08-31

OMP Studio connection and workflow launcher fixes.

### Fixed

- Fixed persistence of the OMP Studio integration settings by passing the plugin name to the Smarty settings template and aligning the settings form lifecycle with the working OJS integration.
- Added Studio URL validation, normalized URL storage, safe shared-secret generation and bounded token TTL handling.
- Restored the missing backend Studio launcher on OMP workflow pages by registering the `TemplateManager::display` hook.
- Added the launcher JavaScript and CSS assets that create the floating “Open in Studio” action on supported workflow, dashboard and reviewer pages.
- Bound the launcher to the OMP `omiIntegration/launch` endpoint while preserving editor, author and reviewer launch modes.

## 1.2.3 - 2026-08-29

PKP/OMP 3.5 workflow-compliance hardening.

### Fixed

- Reviewer Studio launch now selects only the current incomplete OMP review assignment instead of a historical assignment.
- Reviewer API assertions are rejected when their assignment no longer belongs to the submission's current OMP review stage/round.
- Author revision uploads are restricted to the submission's current review stage and current review round.
- Completed review assignments are read-only for the legacy review-result writeback endpoint.
- Legacy free-text recommendation values are rejected instead of being encoded into editor comments.
- Capability discovery now reports the implemented author/reviewer revision write APIs and current plugin version.

### Documentation

- Updated installation and status documentation to OMP 3.5.x / PHP 8.2+.
- Added a PKP/OMP authority and compatibility matrix.
- Added security policy and explicit Plugin Gallery readiness notes.

## 1.2.2 - 2026-08-29

Native OMP/PKP 3.5 review and revision API update.

### Added

- `GET /platform-capabilities` describing OMP-specific native API support instead of assuming OJS feature parity.
- `GET /review-context` exposing assignment and review-round identity from the signed reviewer launch.
- Assignment-scoped reviewer attachment listing and multipart upload.
- Review-round-scoped author revision multipart upload for both internal and external review stages.
- `POST /review-result-v2` with native recommendation-ID validation when the host application supports customizable recommendations.
- `author.revision.write` and `review.revision.write` launch scopes.

### OMP/PKP API alignment

- Reviewer attachments are persisted as `SUBMISSION_FILE_REVIEW_ATTACHMENT` associated with `ASSOC_TYPE_REVIEW_ASSIGNMENT`, matching PKP review history queries.
- Author revisions are persisted as `SUBMISSION_FILE_REVIEW_REVISION` or `SUBMISSION_FILE_INTERNAL_REVIEW_REVISION` associated with `ASSOC_TYPE_REVIEW_ROUND`.
- Submission-file storage, validation and review-round association use `Repo::submissionFile()` and the same PKP repository semantics used by the native submission-file API.
- Reviewer recommendation support is discovered from `Application::hasCustomizableReviewerRecommendation()`. OMP 3.5 currently returns `false`, so no OJS recommendation identifiers are fabricated or encoded into comments.
- Review completion remains authoritative in the native OMP reviewer workflow because completion triggers notifications, logs and invitation finalization beyond a single database field update.

### Security

- File uploads remain bound to the signed launch actor, submission and role scope.
- Reviewer attachments are bound to the concrete review assignment and its review round.
- Author revision review-round IDs are checked against the launched monograph and OMP review stages.
- File genres are validated against the current press; ambiguous genre selection is rejected rather than guessed.

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
