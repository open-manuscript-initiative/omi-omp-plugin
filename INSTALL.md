# Installation

## Requirements

- Open Monograph Press 3.5.x
- PHP 8.2 or later, matching the installed OMP release requirements
- HTTPS for production use
- Open Manuscript Studio for Studio-side integration

## Recommended installation

Download the release `.tar.gz` asset and install it as a Site Administrator through OMP's plugin administration interface. PKP's external-plugin installation flow expects the archive to contain a single directory whose name matches the plugin directory.

This plugin is packaged as:

```text
studioIntegration/
├── StudioIntegrationPlugin.php
├── StudioIntegrationApiHandler.php
├── StudioIntegrationApiController.php
├── StudioIntegrationNativeApiController.php
├── version.xml
├── classes/
├── locale/
└── templates/
```

The filesystem location after installation is:

```text
plugins/generic/studioIntegration/
```

Only Site Administrators should install or upgrade plugin packages.

## Configuration

After enabling the generic plugin, configure:

- **Studio URL** — HTTPS base URL of Open Manuscript Studio;
- **Installation ID** — stable identifier for this OMP installation;
- **Shared secret** — high-entropy integration secret shared with the Studio server;
- **Token lifetime** — short launch assertion lifetime; 300 seconds is recommended.

Do not expose the shared secret in client-side configuration or logs.

## Upgrade

Use the release package matching the supported OMP branch. Back up the OMP database and files before application/plugin upgrades as required by normal PKP administration practice.

The plugin does not install independent database tables. Review forms, review comments, assignments, revisions and files remain native OMP/PKP records.

## Workflow behavior

- Reviewer launches are bound to the current incomplete review assignment.
- Reviewer writeback is rejected after the assignment is completed.
- Reviewer attachments are stored as native PKP review-attachment submission files associated with the concrete review assignment.
- Author revision uploads are accepted only for the current OMP review stage and current review round.
- OMP remains responsible for completing reviews and triggering the related notifications, logs and invitation finalization.

## Plugin Gallery

The official PKP Plugin Gallery is a separate distribution channel. Until a release is accepted there, install the plugin as an external plugin from this repository's release assets. Gallery inclusion must declare explicit OMP compatibility and a published package/checksum accepted by PKP maintainers.

## Security

Studio must never receive direct OMP database credentials or filesystem access. All exchange occurs through OMP application-level endpoints and PKP authorization objects. See `SECURITY.md` for details.
