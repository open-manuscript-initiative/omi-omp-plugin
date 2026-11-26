# OMP volume import manifest and signed source transfer

Native manager/site administrator endpoint, relative to the current press API:

`GET /api/v1/omi-integration/volumes/{submissionId}/import-manifest`

Requires native authentication, an enabled press, access to the selected submission in Production, and a current unpublished queued publication. Other press submissions are not returned. No review or author assertion can authorize this endpoint by itself. Successful responses are private/no-store.

Unlike an OJS issue, an OMP volume is one submission/publication with chapters. The manifest distinguishes authored works and edited volumes, exposes localized volume metadata, series IDs/position, volume DOI, ordered chapter IDs (including source chapter IDs), localized titles/subtitles/abstracts, DOI, pages and chapter contributor references. Contributor emails are omitted.

Source descriptors are restricted to DOCX files in production-ready, copyedit, final and original submission stages, ordered by stage, recency, revision and ID. Native MANUSCRIPT files without a chapter and CHAPTER files with an explicit current chapter association are separate candidates. Review attachments, unknown chapter IDs and supplementary genres are excluded. Missing chapter sources are not inferred from filenames or the full-book manuscript. Custom genres require an explicit mapping.

## Signed volume launch and source reads

The existing native launch operation accepts explicit `mode=volume` and `submissionId`. Only a current press manager/site admin with native Production access can mint this separate assertion; it expires within five minutes, binds the current publication ID and installation, and has exactly the `volume.import.read` scope. It contains a volume identity instead of the ordinary submission claim and targets Studio's dedicated `/integrations/omp/volume-launch` route.

The signed endpoints (relative to the press integration API) are:

- GET `volume-import/manifest`
- GET `volume-import/files/{submissionFileId}/content`

Use `Authorization: OMI payload.signature`. The signed manifest adds per-source relative content paths. Every signed request verifies the signature, exact scope, context and installation, and rechecks current actor access, Production stage, and unpublished publication identity. Binary reads are limited to current eligible DOCX candidates, validated against the actual native file, confined to the configured private storage root, and capped at 32 MiB per file. No binary grant is exposed by the native manifest itself. Ordinary editor/reviewer tokens do not authorize volume reads.

No write grants or new UI action are enabled. The Studio receiver must enforce trusted origins, one-time launch nonce consumption, and an aggregate download limit; the OMP read token itself is reusable for its short lifetime to allow multiple file requests.

Next: Studio volume receiver and explicit choice between full manuscript and chapter sources; reusable press/series/imprint metadata and publication-format ISBNs; reimport conflict handling; verified chapter page ranges and metadata reconciliation; production artifact transfer to native OMP Publication Formats (not OJS galleys). Publishing and format approval remain native OMP decisions. Native browser tests on each supported OMP version are required before enabling whole-volume import.
