# OMP volume import manifest — first slice

Native manager/site administrator endpoint, relative to the current press API:

`GET /api/v1/omi-integration/volumes/{submissionId}/import-manifest`

Requires native authentication, an enabled press, access to the selected submission in Production, and a current unpublished queued publication. Other press submissions are not returned. No review or author assertion can authorize this endpoint by itself. Successful responses are private/no-store.

Unlike an OJS issue, an OMP volume is one submission/publication with chapters. The manifest distinguishes authored works and edited volumes, exposes localized volume metadata, series IDs/position, volume DOI, ordered chapter IDs (including source chapter IDs), localized titles/subtitles/abstracts, DOI, pages and chapter contributor references. Contributor emails are omitted.

Source descriptors are restricted to DOCX files in production-ready, copyedit, final and original submission stages, ordered by stage, recency, revision and ID. Native MANUSCRIPT files without a chapter and CHAPTER files with an explicit current chapter association are separate candidates. Review attachments, unknown chapter IDs and supplementary genres are excluded. Missing chapter sources are not inferred from filenames or the full-book manuscript. Custom genres require an explicit mapping.

This slice is read-only; it exposes no new binary download route, no write grants, and no launch UI. Existing single-manuscript and review routes are unchanged.

Next: signed volume-specific launch and scoped downloads; Studio volume receiver and explicit choice between full manuscript and chapter sources; reusable press/series/imprint metadata and publication-format ISBNs; reimport conflict handling; verified chapter page ranges and metadata reconciliation; production artifact transfer to native OMP Publication Formats (not OJS galleys). Publishing and format approval remain native OMP decisions. Native browser tests on each supported OMP version are required before enabling whole-volume import.
