# Role-specific OMP assignment return

This unreleased change requires the matching Studio OMP role-writeback change. The existing plugin release number is unchanged; this document does not certify a production rollout. The new endpoints are `workflow-assignment` and `assignment-result`, advertised as `omi-assignment-result/1` by `platform-capabilities`. Both use the existing installation HMAC authentication and recheck native permissions.

| Task | Native destination | Access after submission |
| --- | --- | --- |
| Scientific review | Double-anonymous native assignment in its current internal **or** external round; reviewer DOCX and structured snapshot, form, native recommendation and separate public/confidential comments | Native assignment closes after the atomic return; launch and manuscript/form/file reads are refused |
| Language review | The current workflow stage; Word/structured revision and separate native discussions | Open until editorial completion in Studio |
| Translation | Current unpublished volume publication; only the bound target metadata locale, plus language-labelled Word/structured files when required | Open until editorial completion in Studio |

The assignment token binds the press, submission, participant, assigning editor, role, stage, publication version, review round and optional chapter. Returned files retain the chapter association. Internal review revisions use `SUBMISSION_FILE_INTERNAL_REVIEW_REVISION` (20); external revisions use `SUBMISSION_FILE_REVIEW_REVISION` (15). Native recommendation support is feature-detected; OMP 3.5.0-4 does not receive invented legacy recommendation identifiers.

Every translation needs a translated title, abstract and keywords. Full-text translation is required only for the source publication language, an explicitly language-tagged proof associated with a current publication format, or an editor-selected planned full-text language. Localized format names and enabled interface/metadata locales do not imply full-text publication. OMP chapter metadata has no native keyword field; translation metadata assignments are therefore volume-scoped, from the existing editor volume launch. This change does not add chapter metadata translation.

Metadata-only work sends no manuscript files. A full translation's filenames identify its directional language pair. Existing source metadata, other translations, ISBN/ISSN/DOI, contributors, series, chapter order and publication approval remain under native OMP authority. Returning work never publishes a volume.

File creation, metadata, comments, review completion and the durable receipt share a transaction. On native validation failure the original 422 response is returned after rollback and newly stored bytes are deleted. A same-content retry reuses the receipt; changed open work adds a new version. Existing manuscripts remain intact. Revoked editors, changed stages/rounds/publication scope and cross-submission or cross-chapter writes are refused.

Validation: `php tests/role-writeback.php` executes the actual controller against isolated PKP repositories, including both review stages, role privacy, mandatory translated metadata, target-locale isolation, retry and rollback. The existing PHP/contract suite and native OMP compatibility matrix remain required. A real installed OMP/browser acceptance test is still required before production use.
