# Changelog

## 1.5.0.0 — 2026-09-19

### Added

- Add `author-context` discovery so Studio can resolve the current native OMP review round before offering author revision upload.
- Add Studio server-to-server HMAC authentication to reviewer attachment upload, author revision upload and `review-result-v2` without weakening launch-scoped browser access.
- Accept JSON/Base64 workflow-file transfer for signed Studio service writeback while retaining multipart launch-mode uploads.
- Persist native OMP review-form responses through `review-result-v2` and keep OMP review completion authoritative in the native workflow.
- Advertise the native Studio service-writeback contract through `platform-capabilities`.

### Security

- Bind reviewer service writes to the exact submission, reviewer, review assignment and latest review round.
- Bind author revision writes to the signed author, current OMP workflow stage, latest review round and a native revision-request decision.
- Reject stale and cross-submission/cross-assignment/cross-round identifiers.
- Keep shared secrets server-side; service requests use bounded HMAC-SHA256 signatures.
- Limit JSON workflow-file transfers to 25 MiB and validate file names and Base64 content.

### Validation

- Add a CI contract covering native OMP Studio service authentication, workflow authority and non-completion invariants.

## 1.4.2.0 — 2026-09-19

### Security

- Restrict author-mode file listings and binary downloads to the file stages exposed by PKP's native author workflow assignments.
- Strip editorial roles from dual-role accounts when resolving an author launch, preventing author assertions from inheriting editor-only file access.
- Continue rejecting cross-submission file identifiers and keep reviewer-only/review file stages outside the author-visible response.

### Fixed

- Register only one `omi-integration` API controller with PKP 3.5 `APIRouter`; the OMP-native route set is composed into that controller's route group.
- Preserve all existing `/api/v1/omi-integration/*` native endpoint URLs while avoiding PKP's duplicate-handler exception.
- Align the native platform-capability implementation version with the plugin release.

### Validation

- Add CI contracts for single-handler registration, native route preservation, author file-stage filtering and direct-download enforcement.

## 1.4.1.0 — 2026-09-19

### Added

- Expanded authorized contributor import with preferred public name, email, country, URL, biography, competing-interests statement, browse-list visibility and primary-contact state.
- Added structured affiliation payloads with ROR identifiers while preserving the existing flattened affiliation value for compatibility.
- Added PKP CRediT role identifiers and degrees to the contributor payload consumed by Studio.
- Preserved OMP editor/author/reviewer authorization boundaries; reviewer mode still receives no contributor identity data.
- Grant author launches `contributors.read` for their own accessible submission while requiring the separate `review.identity.read` scope for reviewer discovery.

## 1.4.0.0 — 2026-09-18

### Added

- Added editor-authenticated `omi-publication-artifact/1` inspection and transfer for current unpublished OMP 3.5 Production submissions.
- Added provenance-verified self-contained HTML, JATS XML, print PDF and interactive PDF artifact support.
- Added `omi-publication-build@0.1.0` verification for manuscript identity, publication-profile digest, exact output bytes, SHA-256, generator identity, optional renderer-input provenance and deterministic build URN.
- Added granular publication artifact capability discovery and OMP-native authority metadata.

### OMP workflow authority

- Maps each logical Studio output to a stable native OMP Publication Format and `SUBMISSION_FILE_PROOF`.
- Creates Studio-managed Publication Formats unapproved and unavailable; transferred proofs remain non-viewable.
- Rejects changed transfers once the native format is approved/available or any proof is viewable, instead of silently overriding an editor's OMP decision.
- Locks and rechecks the submission/current publication before persistence and never publishes the monograph.
- Keeps exact retries idempotent and retains prior non-viewable proof history when a build changes.

### Validation

- Added standalone publication artifact provenance/format tests.
- Added an OMP authority-boundary controller contract test.
- Added PHP 8.2/8.3/8.4 CI coverage for the new artifact tests.
- Advanced the launcher asset/version marker and cache key to 1.4.0 with the plugin release.

## 1.3.2.0 — 2026-09-09

### Fixed

- Made the Studio launcher stable across PKP History API navigation, DOM remounts and repeated workflow openings.
- Consolidated launcher styling into the single `css/studioIntegration.css` source of truth; JavaScript no longer owns visual positioning.
- Added an explicit launcher instance/version marker so stale or legacy launcher elements are replaced instead of reused.
- Added cache-busted unified launcher assets for OMP to match the OJS integration behavior.

## 1.3.1.0 — 2026-09-09

### Fixed

- Moved the fixed “Open in Studio” launcher to the top-left of the viewport so it no longer overlaps OMP workflow actions.
- Added mobile safe-area offsets and viewport-constrained width for consistent placement on desktop and mobile screens.
- No launch, authorization, submission-selection, or permission logic changed in this patch.

## 1.3.0.0 — 2026-09-08

- Expose public submission options for Studio's native OJS/OMP author submission flow.
- Include accepted languages, unrestricted active sections/series, file components and author terms.
- Require an enabled plugin/context; leave creation, file upload and completion to native PKP permissions.
- Document deployment dependencies and the required real-platform verification.


## Unreleased

## 1.2.6 - 2026-09-05

### Added

- Bound each reviewer launch to the single OMP chapter represented by the files granted to that review assignment.
- Projected the assigned chapter to Studio as a standalone article without parent-monograph or sibling-chapter metadata.

### Security

- Constrained reviewer file listing and download to both the native PKP review-file grant and the chapter signed into the launch assertion.

### Fixed

- Restored PKP 3.5-compatible review comments and form-response persistence without calling non-existent repository convenience methods.
- Verified the complete double-anonymous reviewer workflow, required review form, corrections, separated comments and Studio-to-OMP writeback against a disposable native OMP 3.5 environment.

## 1.2.5 - 2026-08-31

### Fixed

- Routed reviewer-mode launches directly into the Studio peer-review workspace while preserving the signed OMP launch assertion.

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
