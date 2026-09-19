# PKP / OMP 3.5 Compatibility Matrix

This document records how the OMI OMP Integration Plugin maps to Open Monograph Press and PKP 3.5 workflow semantics.

## Authority model

OMP is the system of record. The integration must not replace OMP workflow state with a parallel Studio workflow.

| Area | Authority | Integration behavior |
| --- | --- | --- |
| Press configuration | OMP | Read through OMP context objects |
| Submission identity/state | OMP | Read through `Repo::submission()` |
| Contributors | OMP | Read through publication/contributor repositories |
| Submission files | OMP/PKP | Read/write through `Repo::submissionFile()` and PKP associations; author reads use native author-only assigned file stages |
| Review assignment | OMP/PKP | Concrete `ReviewAssignment`; reviewer launch uses current incomplete assignment |
| Reviewer-visible files | OMP/PKP | Filtered with `ReviewFilesDAO` |
| Review form | OMP/PKP | Native form definition and PKP review-form response DAO persistence |
| Reviewer comments | OMP/PKP | Native public/private `SubmissionCommentDAO` persistence |
| Reviewer attachment | OMP/PKP | `SUBMISSION_FILE_REVIEW_ATTACHMENT` + `ASSOC_TYPE_REVIEW_ASSIGNMENT` |
| Author revision | OMP/PKP | Current round only; review revision file + `ASSOC_TYPE_REVIEW_ROUND` |
| Reviewer recommendation | OMP capability | Never synthesize OJS IDs; use only native options if host supports them |
| Review completion | OMP | Integration does not set completion directly |
| Notifications/event log | OMP | Triggered by native OMP workflow |
| Publication/production decision | OMP | Not overridden by Studio |
| Publication Format | OMP | Studio may create a stable digital format only as unapproved/unavailable |
| Production proof | OMP/PKP | Studio writes `SUBMISSION_FILE_PROOF`; new proofs are never viewable automatically |

## Review-round isolation

OMP review stages and rounds are first-class authorization boundaries. The connector therefore rejects:

- reviewer assertions referring to an assignment outside the submission's current review stage/round;
- reviewer writes after `dateCompleted`;
- author revision uploads to a historical round;
- author revision uploads to a review stage other than the submission's current stage.

This prevents Round 1 data from being written into Round 2 and vice versa.

## Reviewer anonymity

Reviewer launches do not receive contributor or reviewer-identity scopes. A launch is issued only when the assignment's native PKP file grants resolve to exactly one OMP chapter. That chapter ID is signed into the assertion and Studio receives it as a standalone article without parent-monograph, sibling-chapter or contributor metadata. Reviewer source files must pass both the concrete `ReviewAssignment` check through `ReviewFilesDAO` and the signed chapter boundary. The integration must not infer authorization from filenames, user-supplied IDs or Studio-side state.

## Author file privacy

Author launch assertions are least-privilege even when the same OMP account
also holds editor or manager roles. The integration reads the actor's native
workflow-stage assignments, discards every non-author role, and delegates the
remaining stage set to `Repo::submissionFile()->getAssignedFileStages(...,
SUBMISSION_FILE_ACCESS_READ)`. Both file listings and direct binary downloads
apply the resulting allowlist server-side. Reviewer-only, editorial-only,
dependent and other non-author file stages therefore cannot be reached by
changing a file identifier in Studio.

## File semantics

The connector uses PKP file stages and associations rather than generic uploads:

- reviewer-returned files: `SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT` + `PKPApplication::ASSOC_TYPE_REVIEW_ASSIGNMENT`;
- external-review author revisions: `SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION` + `PKPApplication::ASSOC_TYPE_REVIEW_ROUND`;
- internal-review author revisions: `SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_REVISION` + `PKPApplication::ASSOC_TYPE_REVIEW_ROUND`.

File metadata is validated by `Repo::submissionFile()->validate()` before the native submission-file record is added.

Publication artifacts use `SUBMISSION_FILE_PROOF` +
`Application::ASSOC_TYPE_PUBLICATION_FORMAT`. Studio-created publication
formats remain unapproved/unavailable and their proof files remain non-viewable
until an OMP editor explicitly changes those native states. A changed transfer
is rejected after native approval/availability/viewability rather than silently
reversing an editorial decision.

## API route registration

PKP 3.5 accepts only one plugin API controller for a given handler path. The
plugin therefore registers exactly one `omi-integration` controller with
`APIRouter`. The OMP-native controller contributes its routes to that active
route group instead of registering a second handler. This preserves the
existing `/api/v1/omi-integration/*` URLs without relying on duplicate route
registration.

## API usage policy

The plugin prefers OMP/PKP repository and application services. DAO access is used only where PKP 3.5 itself still exposes the relevant workflow through DAOs, including `ReviewFilesDAO`, `ReviewRoundDAO`, review-form DAOs and `GenreDAO`.

The plugin must not perform direct SQL mutations against OMP tables for integration writes. The publication artifact endpoint uses row locks only for concurrency control; object mutations remain in OMP/PKP repositories and the native Publication Format DAO.

## Plugin packaging

The release archive contains one top-level `studioIntegration/` directory and a PKP `version.xml` using the `plugins.generic` type and `StudioIntegrationPlugin` class. The project is licensed under GPL-3.0.

The repository can be proposed to the official PKP Plugin Gallery, but Gallery inclusion is a separate review by PKP maintainers and requires explicit compatibility metadata and a published archive/checksum.

## Validation before a PKP Gallery proposal

Before submitting a release to the Plugin Gallery, verify all of the following on an unmodified supported OMP release:

1. install from the published `.tar.gz` through the OMP UI;
2. enable/disable and open plugin settings without PHP warnings;
3. editor launch and metadata/file reads;
4. author launch and current-round revision upload;
5. double-anonymous reviewer launch without contributor identity leakage;
6. review-form read/write and required-field validation;
7. reviewer attachment upload bound to the assignment;
8. Round 1 → revision → Round 2 isolation;
9. completed-review write rejection;
10. provenance-verified HTML/JATS/PDF transfer into an unpublished Production submission, including idempotent retry and changed-build history;
11. verify that transferred Publication Formats remain unapproved/unavailable and proofs remain non-viewable until native OMP approval;
12. verify that a changed transfer is rejected after native approval/availability/viewability;
13. uninstall/upgrade behavior and regression check of normal OMP review completion.

PHP lint/package CI is necessary but does not replace this installation-level OMP integration test.
