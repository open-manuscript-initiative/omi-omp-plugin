# OMI OMP Integration Plugin

Open Monograph Press integration plugin for connecting OMP with the Open Manuscript Initiative and Open Manuscript Studio.

## Status

Release candidate targeting **OMP 3.5.x**, **PHP 8.2+**, and the OMI Integration API v1.

Profile identifier:

```text
omi-integration/1/omp
```

The plugin follows OMP/PKP workflow authority instead of emulating OJS behavior. OMP remains the system of record for press configuration, submission workflow state, review rounds, reviewer assignments, review completion, notifications, event logs and publication state.

## Architecture

The plugin is a thin adapter. OMP and Open Manuscript Studio remain separate applications with separate databases.

```text
OMP
  |
  | OMI OMP Integration Plugin
  | HTTPS + signed requests
  v
OMI Integration API v1
  |
  v
Open Manuscript Studio
```

Studio must not read the OMP database or private file storage directly.

## Implemented capabilities

- signed, short-lived launch assertions;
- role-scoped editor, author and reviewer access;
- press and monograph metadata reads;
- contributor reads for authorized editorial/author contexts;
- submission-file listing and protected binary transfer;
- reviewer file access constrained by PKP `ReviewFilesDAO`;
- native PKP review-form definition and response persistence;
- assignment-scoped reviewer attachment upload using `SUBMISSION_FILE_REVIEW_ATTACHMENT` and `ASSOC_TYPE_REVIEW_ASSIGNMENT`;
- current-review-round author revision upload using `SUBMISSION_FILE_REVIEW_REVISION` or `SUBMISSION_FILE_INTERNAL_REVIEW_REVISION` and `ASSOC_TYPE_REVIEW_ROUND`;
- review comments with author-visible and editor-only separation;
- capability discovery for OMP-specific reviewer recommendation support;
- OMP-native review completion authority retained in OMP.

Capabilities are advertised only when implemented safely.

## OMP-specific workflow rules

The connector preserves monograph semantics and PKP authorization boundaries. In particular:

- reviewer launch is bound to the current incomplete `ReviewAssignment`;
- reviewer reads and writes are bound to the current OMP review stage and round;
- author revision uploads are rejected for historical review rounds;
- completed reviewer assignments are read-only for writeback;
- reviewer recommendations are not synthesized from OJS identifiers or free-text values;
- review completion is not performed by the integration endpoint because native OMP completion also triggers notifications, logging and invitation finalization.

## Compatibility

| Component | Supported |
| --- | --- |
| Open Monograph Press | 3.5.x |
| PHP | 8.2, 8.3, 8.4 |
| Plugin type | Generic plugin |
| Package directory | `plugins/generic/studioIntegration` |
| License | GPL-3.0 |

See `PKP_COMPATIBILITY.md` for the detailed compatibility and authority matrix.

## Installation

The preferred manual package is the `.tar.gz` release asset. Site Administrators can upload it through the OMP plugin administration interface. The archive contains a single top-level `studioIntegration/` directory, as expected by PKP plugin installation.

See `INSTALL.md` for configuration and upgrade instructions.

## Security

Launch assertions use HMAC-SHA256, short expiration windows and role-specific scopes. Reviewer identity and file access are checked server-side against OMP/PKP objects. Studio never receives OMP database credentials or direct private-file-storage access.

See `SECURITY.md` for the security model and vulnerability reporting guidance.

## Plugin Gallery readiness

The repository and release package follow the PKP generic-plugin package layout and release conventions. Inclusion in the official PKP Plugin Gallery is a separate PKP review process and is not implied by this repository. A Gallery submission must declare the supported OMP releases and reference a published `.tar.gz` asset whose checksum matches the Gallery metadata.

## Remaining roadmap

- production/publication export;
- broader component/chapter synchronization;
- native reviewer recommendation support if/when the host OMP version exposes it;
- automated OMP installation-level integration tests in addition to PHP/package CI.

## License

GNU General Public License v3.0. See `LICENSE`.

## Related specifications

See the Open Manuscript Initiative documentation for the Integration Architecture, OMI Integration API v1 and OMP Integration Profile v1.
