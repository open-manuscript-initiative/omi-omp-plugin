# OMI OMP Integration Plugin

Open Monograph Press integration plugin for connecting OMP with the Open Manuscript Initiative and Open Manuscript Studio.

## Status

Early development release targeting OMP 3.5.x and the OMI Integration API v1.

Profile identifier:

```text
omi-integration/1/omp
```

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

## Initial capabilities

The first implementation focuses on:

- signed launch into Open Manuscript Studio;
- capability discovery;
- press/submission identity mapping;
- metadata read foundation;
- contributor read foundation;
- submission file listing foundation;
- component-aware architecture for chapters and other book parts.

Capabilities are advertised only when implemented safely.

## OMP-specific model

The connector preserves monograph semantics, including:

- presses;
- monographs and edited volumes;
- chapters and other publication components;
- book-level and component-level contributors;
- editor, author, translator and chapter-author scope;
- complete-work or component-level peer review;
- traceable revisions;
- production and catalog workflows.

## Planned capabilities

- protected binary file transfer;
- OMI manuscript import/export;
- chapter/component synchronization;
- revision write-back;
- peer-review assignment synchronization;
- structured anchored review results;
- production and publication export.

## Security

Launch assertions use short-lived HMAC-SHA256 signatures. The connector is designed around least-privilege scopes, server-side authorization and no direct cross-database access.

## License

GNU General Public License v3.0.

## Related specifications

See the Open Manuscript Initiative documentation for:

- Integration Architecture;
- OMI Integration API v1;
- OMP Integration Profile v1.
