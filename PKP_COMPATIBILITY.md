# PKP / OMP 3.5 Compatibility Matrix

This document records how the OMI OMP Integration Plugin maps to Open Monograph Press and PKP 3.5 workflow semantics.

## Authority model

OMP is the system of record. The integration must not replace OMP workflow state with a parallel Studio workflow.

| Area | Authority | Integration behavior |
| --- | --- | --- |
| Press configuration | OMP | Read through OMP context objects |
| Submission identity/state | OMP | Read through `Repo::submission()` |
| Contributors | OMP | Read through publication/contributor repositories |
| Submission files | OMP/PKP | Read/write through `Repo::submissionFile()` and PKP associations |
| Review assignment | OMP/PKP | Concrete `ReviewAssignment`; reviewer launch uses current incomplete assignment |
| Reviewer-visible files | OMP/PKP | Filtered with `ReviewFilesDAO` |
| Review form | OMP/PKP | Native form definition and `Repo::reviewAssignment()->saveReviewFormResponse()` |
| Reviewer comments | OMP/PKP | Native public/private review comments |
| Reviewer attachment | OMP/PKP | `SUBMISSION_FILE_REVIEW_ATTACHMENT` + `ASSOC_TYPE_REVIEW_ASSIGNMENT` |
| Author revision | OMP/PKP | Current round only; review revision file + `ASSOC_TYPE_REVIEW_ROUND` |
| Reviewer recommendation | OMP capability | Never synthesize OJS IDs; use only native options if host supports them |
| Review completion | OMP | Integration does not set completion directly |
| Notifications/event log | OMP | Triggered by native OMP workflow |
| Publication/production decision | OMP | Not overridden by Studio |

## Review-round isolation

OMP review stages and rounds are first-class authorization boundaries. The connector therefore rejects:

- reviewer assertions referring to an assignment outside the submission's current review stage/round;
- reviewer writes after `dateCompleted`;
- author revision uploads to a historical round;
- author revision uploads to a review stage other than the submission's current stage.

This prevents Round 1 data from being written into Round 2 and vice versa.

## Reviewer anonymity

Reviewer launches do not receive contributor or reviewer-identity scopes. Reviewer source files are filtered against the concrete review assignment through the native PKP `ReviewFilesDAO` boundary. The integration must not infer authorization from filenames, user-supplied IDs or Studio-side state.

## File semantics

The connector uses PKP file stages and associations rather than generic uploads:

- reviewer-returned files: `SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT` + `PKPApplication::ASSOC_TYPE_REVIEW_ASSIGNMENT`;
- external-review author revisions: `SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION` + `PKPApplication::ASSOC_TYPE_REVIEW_ROUND`;
- internal-review author revisions: `SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_REVISION` + `PKPApplication::ASSOC_TYPE_REVIEW_ROUND`.

File metadata is validated by `Repo::submissionFile()->validate()` before the native submission-file record is added.

## API usage policy

The plugin prefers OMP/PKP repository and application services. DAO access is used only where PKP 3.5 itself still exposes the relevant workflow through DAOs, including `ReviewFilesDAO`, `ReviewRoundDAO`, review-form DAOs and `GenreDAO`.

The plugin must not perform direct SQL against OMP tables for integration writes.

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
10. uninstall/upgrade behavior and regression check of normal OMP review completion.

PHP lint/package CI is necessary but does not replace this installation-level OMP integration test.
