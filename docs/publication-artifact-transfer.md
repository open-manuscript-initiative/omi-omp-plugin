# Publication artifact transfer

Plugin version **1.4.0.0** adds an editor-authenticated publication artifact
transfer protocol for Open Manuscript Studio.

The endpoint is available inside the press-scoped plugin API:

```text
POST /api/v1/omi-integration/publication-artifact
```

OMP remains the publication authority. A successful transfer creates or reuses
an OMP **Publication Format** and stores a Production proof file. New
OMI-managed publication formats are deliberately created **unapproved** and
**unavailable**, and transferred proof files are **not viewable**. The endpoint
does not publish the monograph, approve a format, make it available in the
catalog, or make the proof public.

## Capability discovery

`GET /api/v1/omi-integration` advertises:

```text
editor.publication-artifact.write
publication.html.write
publication.jats.write
publication.pdf.write
publication.provenance.verify
```

The `publicationArtifacts` object declares the protocol, required provenance
model, available formats, and the native OMP authority boundary.

Supported formats are:

| Format | Media type | Maximum decoded size | OMP format label |
| --- | --- | ---: | --- |
| `html` | `text/html` | 8 MiB | HTML |
| `jats` | `application/xml` | 8 MiB | JATS XML |
| `pdf-print` | `application/pdf` | 32 MiB | PDF |
| `pdf-interactive` | `application/pdf` | 32 MiB | PDF (Interactive) |

HTML availability is conditional on the OMP HTML Monograph File plugin.

## Inspect before transfer

The client first sends:

```json
{
  "action": "inspect",
  "submissionId": 42,
  "manuscriptId": "omi-manuscript-id"
}
```

Inspection succeeds only when:

- the integration plugin is enabled for the current press;
- the current user is authenticated with an editorial role;
- the editor can access the submission in the Production stage;
- the target is the current unpublished publication version.

The response returns the current publication ID, supported submission locales,
eligible file genres, supported artifact formats, the required provenance
model, and the OMP publication-authority defaults.

## Transfer request

A transfer includes the exact artifact bytes as base64 plus the complete Studio
publication-build manifest:

```json
{
  "action": "transfer",
  "submissionId": 42,
  "publicationId": 51,
  "manuscriptId": "omi-manuscript-id",
  "locale": "en",
  "genreId": 1,
  "format": "pdf-print",
  "mediaType": "application/pdf",
  "fileName": "monograph.pdf",
  "artifactBase64": "...",
  "build": {
    "model": "omi-publication-build",
    "version": "0.1.0",
    "id": "urn:omi:publication-build:sha256:..."
  },
  "confirmed": true
}
```

The shortened `build` example above is illustrative only. The complete
`omi-publication-build@0.1.0` object is required.

## Provenance verification

Before any OMP publication format or proof is persisted, the plugin
independently verifies:

- the provenance model and version;
- OMI manuscript identity;
- committed revision and manuscript-state digest presence;
- publication-profile identity and digest;
- output format, media type and original filename;
- exact decoded byte length;
- SHA-256 digest of the transferred bytes;
- Studio generator and renderer identity;
- optional renderer-input provenance for PDF;
- the deterministic publication-build URN.

The build identifier is recalculated from canonical provenance data. A mismatch
returns HTTP 422 and leaves no OMP publication record or stored artifact behind.

The `.omi-build.json` data is verified as transfer provenance but is not
published as a separate OMP file. The receipt returns the verified build ID and
artifact SHA-256.

## OMP-native representation mapping

Each logical OMI artifact is mapped to a stable OMP Publication Format identity
using:

```text
manuscript ID + locale + publication format
```

The generated Publication Format:

- is digital (ONIX Product Form `DA`);
- is tied to the current Publication;
- receives a stable OMI-derived `urlPath`;
- starts with `isApproved = false`;
- starts with `isAvailable = false`.

The transferred file uses:

```text
SubmissionFile::SUBMISSION_FILE_PROOF
Application::ASSOC_TYPE_PUBLICATION_FORMAT
```

and starts with `viewable = false`.

An identical retry reuses the existing proof and does not create another file.
A changed build creates another non-viewable proof under the same logical
Publication Format while earlier proof history remains in OMP.

## Native editorial lock

Studio does not silently undo an OMP editor's approval decision.

If a changed artifact targets an OMI-managed Publication Format that is already
approved, available, or contains a viewable proof, the transfer is rejected
with HTTP 409. The editor must first reopen that representation in the native
OMP workflow before Studio can add a replacement proof.

An exact idempotent retry of an already transferred artifact is still safe and
returns the existing receipt without changing OMP state.

## Concurrency and authority

Immediately before persistence, the plugin locks the submission and publication
rows and repeats the authorization and publication-state checks. All actual OMP
object mutations use native OMP/PKP repositories or the Publication Format DAO.

This protocol never:

- publishes a monograph;
- approves a Publication Format;
- makes a Publication Format available;
- makes a proof viewable;
- changes an editorial decision;
- accepts a historical/non-current publication version;
- grants access based only on a submission ID;
- treats the OMI shared secret as an editor credential.

The editor authenticates through native OMP API/session authorization.
