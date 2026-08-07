# Installation

## Requirements

- Open Monograph Press 3.5.x
- PHP version supported by the installed OMP release
- Open Manuscript Studio for Studio-side integration

## Plugin directory

Install the repository contents as:

```text
plugins/generic/studioIntegration/
```

The plugin package root must contain `version.xml` and `StudioIntegrationPlugin.php`.

## OMP Plugin Gallery / upload package

For an installable archive, package the directory itself rather than the repository name, for example:

```text
studioIntegration/
├── StudioIntegrationPlugin.php
├── StudioIntegrationApiHandler.php
├── version.xml
├── classes/
├── locale/
└── templates/
```

Then create a `.tar.gz` archive whose top-level directory is `studioIntegration`.

## Configuration

After enabling the generic plugin, configure:

- **Studio URL** — base URL of Open Manuscript Studio;
- **Installation ID** — stable identifier for this OMP installation;
- **Shared secret** — random integration secret shared with Studio;
- **Token lifetime** — short launch assertion lifetime; 300 seconds is recommended for initial testing.

Use HTTPS in production.

## Current status

Version 1.1.0 is an integration foundation. It currently advertises only the `launch` capability. Metadata, contributors, files, manuscript synchronization, revisions, review and publication integration are present in the architecture but must not be treated as implemented until explicitly advertised by `/omiIntegration/capabilities`.

## Security

Do not provide Studio with direct OMP database credentials or filesystem access. All future data exchange must be authorized through OMP application-level integration endpoints.
