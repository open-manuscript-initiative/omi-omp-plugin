<?php
namespace APP\plugins\generic\studioIntegration;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\studioIntegration\classes\Adapters\Omp35Adapter;
use APP\plugins\generic\studioIntegration\classes\Core\LaunchToken;
use APP\plugins\generic\studioIntegration\classes\Core\WorkflowSnapshot;
use APP\plugins\generic\studioIntegration\classes\Core\MetadataReconciliation;
use PKP\observers\events\MetadataChanged;
use APP\plugins\generic\studioIntegration\classes\Core\PublicationArtifactDocument;
use APP\submission\Submission;
use Illuminate\Support\Facades\DB;
use PKP\plugins\PluginRegistry;
use PKP\submissionFile\SubmissionFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as IlluminateRequest;
use Illuminate\Support\Facades\Route;
use PKP\config\Config;
use PKP\core\Core;
use PKP\core\PKPBaseController;
use PKP\db\DAORegistry;
use PKP\reviewForm\ReviewFormElement;
use PKP\reviewForm\ReviewFormResponse;
use PKP\security\Role;
use PKP\stageAssignment\StageAssignment;
use Illuminate\Http\Exceptions\HttpResponseException;
use APP\plugins\generic\studioIntegration\classes\Core\WorkflowFileDocument;
use PKP\security\authorization\SubmissionFileAccessPolicy;
use PKP\submission\ReviewFilesDAO;
use PKP\submission\SubmissionComment;
use PKP\submission\reviewAssignment\ReviewAssignment;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StudioIntegrationApiController extends PKPBaseController
{
    private const SERVICE_CLOCK_SKEW_SECONDS = 300;

    public function __construct(private StudioIntegrationPlugin $plugin)
    {
    }

    public function getHandlerPath(): string
    {
        return 'omi-integration';
    }

    public function getRouteGroupMiddleware(): array
    {
        return ['has.context'];
    }

    public function getGroupRoutes(): void
    {
        Route::get('', $this->capabilities(...))->name('api.omiIntegration.capabilities');
        Route::post('metadata', $this->metadata(...))->middleware(['has.user', self::roleAuthorizer([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN])])->name('api.omiIntegration.metadata');
        Route::get('workflow-status', $this->workflowStatus(...))->middleware(['has.user', self::roleAuthorizer([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN])])->name('api.omiIntegration.workflowStatus');
        Route::get('submission-options', $this->submissionOptions(...))->name('api.omiIntegration.submissionOptions');
        Route::get('volumes/{submissionId}/import-manifest', $this->volumeImportManifest(...))
            ->whereNumber('submissionId')
            ->middleware(['has.user', self::roleAuthorizer([
                Role::ROLE_ID_MANAGER,
                Role::ROLE_ID_SITE_ADMIN,
            ])])
            ->name('api.omiIntegration.volumeImportManifest');
        Route::get('volume-import/manifest', $this->signedVolumeManifest(...))
            ->name('api.omiIntegration.signedVolumeManifest');
        Route::get('volume-import/files/{submissionFileId}/content', $this->signedVolumeFile(...))
            ->whereNumber('submissionFileId')
            ->name('api.omiIntegration.signedVolumeFile');
        Route::get('submission', $this->submission(...))->name('api.omiIntegration.submission');
        Route::get('contributors', $this->contributors(...))->name('api.omiIntegration.contributors');
        Route::get('reviewers', $this->reviewers(...))->name('api.omiIntegration.reviewers');
        Route::get('files', $this->files(...))->name('api.omiIntegration.files');
        Route::get('review-form', $this->reviewForm(...))->name('api.omiIntegration.reviewForm');
        Route::get('files/{submissionFileId}/content', $this->fileContent(...))
            ->whereNumber('submissionFileId')
            ->name('api.omiIntegration.fileContent');
        Route::post('work-contexts', $this->workContexts(...))->name('api.omiIntegration.workContexts');
        Route::post('workflow-assignment', $this->workflowAssignment(...))->name('api.omiIntegration.workflowAssignment');
        Route::post('assignment-result', $this->assignmentResult(...))->name('api.omiIntegration.assignmentResult');
        Route::post('review-result', $this->reviewResult(...))->name('api.omiIntegration.reviewResult');
        Route::post('chapter-page-range', $this->chapterPageRange(...))
            ->middleware(['has.user', self::roleAuthorizer([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN])])
            ->name('api.omiIntegration.chapterPageRange');
        Route::post('publication-artifact', $this->publicationArtifact(...))
            ->middleware(['has.user', self::roleAuthorizer([
                Role::ROLE_ID_MANAGER,
                Role::ROLE_ID_SITE_ADMIN,
                Role::ROLE_ID_SUB_EDITOR,
                Role::ROLE_ID_ASSISTANT,
            ])])
            ->name('api.omiIntegration.publicationArtifact');

        // Keep all existing /omi-integration native URLs while satisfying the
        // PKP 3.5 requirement that a plugin registers each handler path once.
        // The native controller contributes routes to this already-active route
        // group but is not independently registered with APIRouter.
        (new StudioIntegrationNativeApiController($this->plugin))->getGroupRoutes();
    }

    /**
     * Transfer a provenance-verified publication artifact into the current
     * unpublished OMP production version.
     *
     * OMP remains authoritative. New OMI-managed publication formats are
     * created unapproved/unavailable and proof files are not viewable. A
     * changed artifact cannot replace a format that an editor has already
     * approved, made available, or made viewable in the native OMP workflow.
     */
    public function chapterPageRange(IlluminateRequest $input): JsonResponse
    {
        $data = $input->validate([
            'action' => 'required|in:inspect,apply',
            'installationId' => 'required|string|max:255',
            'contextId' => 'required|integer|min:1',
            'submissionId' => 'required|integer|min:1',
            'publicationId' => 'required|integer|min:1',
            'chapterId' => 'required|integer|min:1',
            'firstPrintedPage' => 'required_if:action,apply|integer|min:1|max:999999',
            'lastPrintedPage' => 'required_if:action,apply|integer|min:1|max:999999',
            'expectedWorkflowToken' => 'required_if:action,apply|string|size:64',
            'confirmed' => 'required_if:action,apply|accepted',
        ]);
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $user = $request->getUser();
        if (!$context || !$user || !$this->plugin->getEnabled($context->getId())
            || (int)$context->getId() !== (int)$data['contextId']
            || !hash_equals($this->plugin->getInstallationId($context->getId(), $request), $data['installationId'])) {
            return $this->error('editor_required', 'Matching authenticated press installation required.', 403);
        }
        $submissionId = (int)$data['submissionId'];
        $publicationId = (int)$data['publicationId'];
        $chapterId = (int)$data['chapterId'];
        $dao = DAORegistry::getDAO('ChapterDAO');
        $load = function () use ($context, $user, $submissionId, $publicationId, $chapterId, $dao) {
            $target = $this->plugin->volumeImportTarget($context, $user, $submissionId, $publicationId);
            if (!$target || !Repo::submission()->canEditPublication($submissionId, $user->getId())) {
                abort(403, 'Native editorial permission on the exact unpublished Production version required.');
            }
            $chapter = $dao->getChapter($chapterId, $publicationId);
            if (!$chapter || (int)$chapter->getData('publicationId') !== $publicationId) {
                abort(404, 'Chapter not found in the selected publication.');
            }
            return [$target[0], $target[1], $chapter];
        };
        $snapshot = function ($submission, $publication, $chapter) use ($data, $context) {
            $state = [
                'protocol' => 'omi-chapter-page-range/1',
                'installationId' => $data['installationId'],
                'contextId' => (int)$context->getId(),
                'submissionId' => (int)$submission->getId(),
                'publicationId' => (int)$publication->getId(),
                'chapterId' => (int)$chapter->getId(),
                'sourceChapterId' => $chapter->getSourceChapterId(),
                'pages' => $chapter->getPages(),
                'published' => false,
            ];
            $state['workflowToken'] = hash('sha256', json_encode([
                $state, $submission->getData('stageId'), $submission->getData('status'),
                $publication->getData('status'), $publication->getData('lastModified'),
                $chapter->getSequence(), $chapter->getTitle(), $chapter->getSubtitle(),
                $chapter->getAbstract(), $chapter->getDoi(), $chapter->isPageEnabled(),
            ], JSON_THROW_ON_ERROR));
            return $state;
        };
        if ($data['action'] === 'inspect') {
            return response()->json(['snapshot' => $snapshot(...$load())])->header('Cache-Control', 'private, no-store');
        }
        $first = (int)$data['firstPrintedPage'];
        $last = (int)$data['lastPrintedPage'];
        if ($last < $first) return $this->error('invalid_page_range', 'Printed pages must be ordered.', 422);
        $pages = $first === $last ? (string)$first : $first . '-' . $last;
        $result = DB::transaction(function () use ($data, $submissionId, $publicationId, $chapterId, $dao, $load, $snapshot, $pages) {
            DB::table('submissions')->where('submission_id', $submissionId)->lockForUpdate()->first();
            DB::table('publications')->where('publication_id', $publicationId)->lockForUpdate()->first();
            DB::table('submission_chapters')->where('chapter_id', $chapterId)->where('publication_id', $publicationId)->lockForUpdate()->first();
            DB::table('submission_chapter_settings')->where('chapter_id', $chapterId)->lockForUpdate()->get();
            [$submission, $publication, $chapter] = $load();
            $before = $snapshot($submission, $publication, $chapter);
            if (!hash_equals($before['workflowToken'], $data['expectedWorkflowToken'])) abort(409, 'OMP chapter or workflow changed. Inspect again.');
            $changed = $before['pages'] !== $pages;
            if ($changed) {
                $chapter->setPages($pages);
                $dao->updateLocaleFields($chapter);
            }
            $after = $snapshot(...$load());
            if ($after['pages'] !== $pages) abort(409, 'OMP did not retain the requested page range.');
            return ['snapshot' => $after, 'receipt' => [
                'protocol' => 'omi-chapter-page-range/1',
                'installationId' => $after['installationId'], 'contextId' => $after['contextId'],
                'submissionId' => $submissionId, 'publicationId' => $publicationId, 'chapterId' => $chapterId,
                'previousPages' => $before['pages'], 'pages' => $pages,
                'previousWorkflowToken' => $before['workflowToken'], 'workflowToken' => $after['workflowToken'],
                'unchanged' => !$changed, 'published' => false,
            ]];
        });
        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    public function metadata(IlluminateRequest $input): JsonResponse
    {
        $data = $input->validate([
            'action' => 'required|in:inspect,apply', 'submissionId' => 'required|integer|min:1',
            'installationId' => 'required|string|max:255', 'contextId' => 'required|integer|min:1',
            'publicationId' => 'required_if:action,apply|integer|min:1',
            'locale' => 'required_if:action,apply|string|max:32',
            'expectedStateToken' => 'required_if:action,apply|string|size:64',
            'fields' => 'required_if:action,apply|array|min:1|max:4',
            'confirmed' => 'required_if:action,apply|accepted',
        ]);
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $user = $request->getUser();
        if (!$context || !$user || !$this->plugin->getEnabled($context->getId()) || (int)$context->getId() !== (int)$data['contextId'] || !hash_equals($this->plugin->getInstallationId($context->getId(), $request), $data['installationId'])) return $this->error('editor_required', 'An authenticated editorial user is required.', 403);
        $load = function () use ($data, $context, $user) {
            $submission = Repo::submission()->get((int)$data['submissionId']);
            if (!$submission || (int)$submission->getData('contextId') !== (int)$context->getId()) abort(404, 'Submission not found.');
            $stages = Repo::user()->getAccessibleWorkflowStages($user->getId(), $context->getId(), $submission);
            $roles = $stages[(int)$submission->getData('stageId')] ?? [];
            if (!array_intersect($roles, [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN])) abort(403, 'Editorial access to this submission stage is required.');
            $publication = Repo::publication()->get((int)$submission->getData('currentPublicationId'));
            if (!$publication || (int)$publication->getData('submissionId') !== (int)$submission->getId()) abort(409, 'The current publication is unavailable.');
            return [$submission, $publication];
        };
        [$submission, $publication] = $load();
        $canWrite = fn($s, $p) => (int)$p->getData('status') === Submission::STATUS_QUEUED && Repo::submission()->canEditPublication($s->getId(), $user->getId());
        $snapshot = function ($s, $p) use ($context, $canWrite) {
            $adapter = new Omp35Adapter();
            return MetadataReconciliation::snapshot($s, $p, $context->getSupportedSubmissionMetadataLocales(), $adapter->reconciliationKeywords($p, (string)$s->getData('locale')), $adapter->mapContributors($s), $canWrite($s, $p));
        };
        if ($data['action'] === 'inspect') return response()->json(['snapshot' => $snapshot($submission, $publication)])->header('Cache-Control', 'private, no-store');
        $result = DB::transaction(function () use ($data, $context, $load, $canWrite, $snapshot) {
            DB::table('submissions')->where('submission_id', (int)$data['submissionId'])->lockForUpdate()->first();
            DB::table('publications')->where('publication_id', (int)$data['publicationId'])->lockForUpdate()->first();
            DB::table('publication_settings')->where('publication_id', (int)$data['publicationId'])->lockForUpdate()->get();
            [$submission, $publication] = $load();
            if (!$canWrite($submission, $publication)) abort(403, 'Native OMP metadata write permission on an unpublished publication is required.');
            $current = $snapshot($submission, $publication);
            if ((int)$data['publicationId'] !== (int)$publication->getId() || !hash_equals($current['stateToken'], (string)$data['expectedStateToken'])) abort(409, 'OMP metadata changed. Inspect and compare again.');
            try { $params = MetadataReconciliation::patch($publication, (string)$data['locale'], (array)$data['fields'], $context->getSupportedSubmissionMetadataLocales(), $current['metadata']['keywords']); }
            catch (\InvalidArgumentException $e) { abort(422, $e->getMessage()); }
            $params['id'] = $publication->getId();
            $params['submissionId'] = $submission->getId();
            $errors = Repo::publication()->validate($publication, $params, $submission, $context);
            if ($errors) abort(422, 'Native OMP metadata validation rejected the proposal.');
            Repo::publication()->edit($publication, $params);
            [$updatedSubmission, $updated] = $load();
            if ((int)$updated->getId() !== (int)$publication->getId()) abort(409, 'Current OMP publication changed.');
            $after = $snapshot($updatedSubmission, $updated);
            foreach (array_keys($data['fields']) as $field) {
                $expected = $field === 'keywords' ? $params[$field][$data['locale']] : MetadataReconciliation::plainText($params[$field][$data['locale']]);
                $actual = $after['metadata'][$field][$data['locale']] ?? ($field === 'keywords' ? [] : '');
                if ($actual !== $expected) abort(409, 'OMP did not retain the selected metadata value.');
            }
            return [
                'snapshot' => $after,
                'receipt' => ['protocol' => MetadataReconciliation::PROTOCOL, 'submissionId' => (int)$submission->getId(), 'publicationId' => (int)$publication->getId(), 'locale' => $data['locale'], 'fields' => array_keys($data['fields']), 'previousStateToken' => $current['stateToken'], 'stateToken' => $after['stateToken'], 'contextId' => (int)$context->getId(), 'installationId' => $data['installationId'], 'published' => false],
                'submission' => $submission,
            ];
        });
        event(new MetadataChanged($result['submission']));
        unset($result['submission']);
        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    /** Read the current OMP editorial state using native, submission-scoped access. */
    public function workflowStatus(IlluminateRequest $input): JsonResponse
    {
        $data = $input->validate(['submissionId' => 'required|integer|min:1']);
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $user = $request->getUser();
        if (!$context || !$user || !$this->plugin->getEnabled($context->getId())) {
            return $this->error('editor_required', 'An authenticated editorial user is required.', 403);
        }
        $submission = Repo::submission()->get((int)$data['submissionId']);
        if (!$submission || (int)$submission->getData('contextId') !== (int)$context->getId()) {
            return $this->error('submission_not_found', 'Submission not found.', 404);
        }
        $stages = Repo::user()->getAccessibleWorkflowStages($user->getId(), $context->getId(), $submission);
        $allowed = false;
        foreach ($stages as $roles) {
            if (array_intersect($roles, [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN])) $allowed = true;
        }
        if (!$allowed) return $this->error('editor_required', 'Editorial access to this submission is required.', 403);
        $publication = Repo::publication()->get((int)$submission->getData('currentPublicationId'));
        return response()->json(WorkflowSnapshot::create($submission, $publication))
            ->header('Cache-Control', 'private, no-store');
    }

    public function publicationArtifact(IlluminateRequest $input): JsonResponse
    {
        $data = $input->validate([
            'action' => 'required|in:inspect,transfer',
            'submissionId' => 'required|integer|min:1',
            'manuscriptId' => 'required|string|max:128',
            'publicationId' => 'required_if:action,transfer|integer|min:1',
            'locale' => 'required_if:action,transfer|string|max:32',
            'genreId' => 'required_if:action,transfer|integer|min:1',
            'format' => 'required_if:action,transfer|in:html,jats,pdf-print,pdf-interactive,epub',
            'mediaType' => 'required_if:action,transfer|string|max:128',
            'fileName' => 'required_if:action,transfer|string|max:255',
            'artifactBase64' => 'required_if:action,transfer|string|max:90000000',
            'build' => 'required_if:action,transfer|array',
            'confirmed' => 'required_if:action,transfer|accepted',
        ]);

        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $user = $request->getUser();
        if (!$context || !$user || !$this->plugin->getEnabled($context->getId())) {
            return $this->error(
                'editor_required',
                'An authenticated editor in an enabled press is required.',
                403
            );
        }

        $submissionId = (int)$data['submissionId'];
        $authorize = function () use ($context, $user, $submissionId, $data) {
            $submission = Repo::submission()->get($submissionId);
            if (
                !$submission ||
                (int)$submission->getData('contextId') !== (int)$context->getId()
            ) {
                abort(404, 'Submission not found.');
            }

            $stages = Repo::user()->getAccessibleWorkflowStages(
                $user->getId(),
                $context->getId(),
                $submission
            );
            if (!array_intersect(
                $stages[WORKFLOW_STAGE_ID_PRODUCTION] ?? [],
                [
                    Role::ROLE_ID_MANAGER,
                    Role::ROLE_ID_SITE_ADMIN,
                    Role::ROLE_ID_SUB_EDITOR,
                    Role::ROLE_ID_ASSISTANT,
                ]
            )) {
                abort(
                    403,
                    'Editorial access to this monograph in Production is required.'
                );
            }

            $publication = Repo::publication()->get(
                (int)$submission->getData('currentPublicationId')
            );
            if (
                (int)$submission->getData('stageId') !== WORKFLOW_STAGE_ID_PRODUCTION ||
                !$publication ||
                (int)$publication->getData('status') !== Submission::STATUS_QUEUED ||
                (
                    isset($data['publicationId']) &&
                    (int)$data['publicationId'] !== (int)$publication->getId()
                )
            ) {
                abort(
                    409,
                    'Inspect the current unpublished Production version before transferring a publication artifact.'
                );
            }

            return [$submission, $publication];
        };

        [$submission, $publication] = $authorize();

        $reader = PluginRegistry::getPlugin('generic', 'htmlmonographfileplugin');
        $htmlAvailable = $reader && $reader->getEnabled($context->getId());

        $genres = [];
        $enabledGenres = DAORegistry::getDAO('GenreDAO')
            ->getEnabledByContextId($context->getId());
        while ($genre = $enabledGenres->next()) {
            if (!$genre->getDependent() && !$genre->getSupplementary()) {
                $genres[] = [
                    'id' => (int)$genre->getId(),
                    'label' => $genre->getLocalizedName(),
                ];
            }
        }

        $locales = array_values($context->getSupportedSubmissionLocales());

        if ($data['action'] === 'inspect') {
            return response()->json([
                'protocol' => PublicationArtifactDocument::PROTOCOL,
                'submissionId' => $submissionId,
                'publicationId' => (int)$publication->getId(),
                'title' => $publication->getLocalizedData('title'),
                'locales' => $locales,
                'genres' => $genres,
                'formats' => PublicationArtifactDocument::formats((bool)$htmlAvailable),
                'provenance' => [
                    'required' => true,
                    'model' => PublicationArtifactDocument::BUILD_MODEL,
                    'version' => PublicationArtifactDocument::BUILD_VERSION,
                    'digest' => 'sha256',
                ],
                'authority' => [
                    'representation' => 'publicationFormat',
                    'formatApprovedByDefault' => false,
                    'formatAvailableByDefault' => false,
                    'proofViewableByDefault' => false,
                ],
                'published' => false,
            ]);
        }

        if (
            !in_array($data['locale'], $locales, true) ||
            !in_array((int)$data['genreId'], array_column($genres, 'id'), true)
        ) {
            return $this->error(
                'invalid_options',
                'Choose an enabled publication language and file genre.',
                422
            );
        }

        if ($data['format'] === 'html' && !$htmlAvailable) {
            return $this->error(
                'html_reader_required',
                'Enable the OMP HTML Monograph File plugin before transferring HTML.',
                409
            );
        }

        try {
            $artifact = PublicationArtifactDocument::validateTransfer(
                (string)$data['format'],
                (string)$data['mediaType'],
                (string)$data['fileName'],
                (string)$data['artifactBase64'],
                (array)$data['build'],
                (string)$data['manuscriptId']
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error(
                'invalid_publication_artifact',
                $e->getMessage(),
                422
            );
        }

        $storedName =
            'omi-' .
            preg_replace('/[^a-z0-9]+/i', '-', (string)$data['format']) .
            '-' .
            $artifact['sha256'] .
            '.' .
            $artifact['extension'];
        $path = PublicationArtifactDocument::path(
            (string)$data['manuscriptId'],
            (string)$data['locale'],
            (string)$data['format']
        );

        $temporary = tmpfile();
        if (!$temporary) {
            return $this->error(
                'storage_error',
                'Unable to allocate temporary storage.',
                500
            );
        }

        $fileId = null;
        $used = false;
        try {
            $bytes = $artifact['bytes'];
            if (fwrite($temporary, $bytes) !== strlen($bytes)) {
                throw new \RuntimeException('Unable to write publication artifact.');
            }

            $dir = Repo::submissionFile()->getSubmissionDir(
                $context->getId(),
                $submissionId
            );
            $fileId = app()->get('file')->add(
                stream_get_meta_data($temporary)['uri'],
                $dir .
                    '/' .
                    bin2hex(random_bytes(16)) .
                    '.' .
                    $artifact['extension']
            );

            $receipt = DB::transaction(function () use (
                $authorize,
                $submissionId,
                $data,
                $path,
                $storedName,
                $fileId,
                $context,
                $user,
                $artifact,
                &$used
            ) {
                DB::table('submissions')
                    ->where('submission_id', $submissionId)
                    ->lockForUpdate()
                    ->first();
                DB::table('publications')
                    ->where('publication_id', (int)$data['publicationId'])
                    ->lockForUpdate()
                    ->first();

                [$submission, $publication] = $authorize();

                $publicationFormatDao = Application::getRepresentationDAO();
                $publicationFormat = null;
                foreach (
                    $publicationFormatDao->getByPublicationId(
                        (int)$publication->getId(),
                        (int)$context->getId()
                    ) as $candidate
                ) {
                    if ((string)$candidate->getData('urlPath') === $path) {
                        $publicationFormat = $candidate;
                        break;
                    }
                }

                $existingProofs = [];
                if ($publicationFormat) {
                    $proofs = Repo::submissionFile()
                        ->getCollector()
                        ->filterBySubmissionIds([$submissionId])
                        ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_PROOF])
                        ->filterByAssoc(
                            Application::ASSOC_TYPE_PUBLICATION_FORMAT,
                            [(int)$publicationFormat->getId()]
                        )
                        ->getMany();
                    foreach ($proofs as $proof) {
                        $existingProofs[] = $proof;
                    }

                    foreach ($existingProofs as $proof) {
                        if (
                            (string)$proof->getData('name', (string)$data['locale']) ===
                            $storedName
                        ) {
                            return [
                                'publicationFormatId' => (int)$publicationFormat->getId(),
                                'submissionFileId' => (int)$proof->getId(),
                                'unchanged' => true,
                                'formatApproved' => (bool)$publicationFormat->getIsApproved(),
                                'formatAvailable' => (bool)$publicationFormat->getIsAvailable(),
                                'proofViewable' => (bool)$proof->getData('viewable'),
                            ];
                        }
                    }

                    $hasViewableProof = false;
                    foreach ($existingProofs as $proof) {
                        if ((bool)$proof->getData('viewable')) {
                            $hasViewableProof = true;
                            break;
                        }
                    }

                    if (
                        (bool)$publicationFormat->getIsApproved() ||
                        (bool)$publicationFormat->getIsAvailable() ||
                        $hasViewableProof
                    ) {
                        abort(
                            409,
                            'The native OMP publication format is already approved, available, or viewable. Reopen it in OMP before replacing its proof.'
                        );
                    }
                } else {
                    $publicationFormat = $publicationFormatDao->newDataObject();
                    $publicationFormat->setData(
                        'publicationId',
                        (int)$publication->getId()
                    );
                    $publicationFormat->setPhysicalFormat(false);
                    $publicationFormat->setIsApproved(false);
                    $publicationFormat->setIsAvailable(false);
                    $publicationFormat->setEntryKey('DA');
                    $publicationFormat->setData(
                        'name',
                        $artifact['label'],
                        (string)$data['locale']
                    );
                    $publicationFormat->setData('urlPath', $path);
                    $publicationFormat->setSequence(REALLY_BIG_NUMBER);
                    $publicationFormatId =
                        $publicationFormatDao->insertObject($publicationFormat);
                    $publicationFormat = $publicationFormatDao->getById(
                        (int)$publicationFormatId,
                        (int)$publication->getId(),
                        (int)$context->getId()
                    );
                }

                if (!$publicationFormat) {
                    abort(500, 'Unable to create the OMP publication format.');
                }

                $params = [
                    'fileId' => $fileId,
                    'submissionId' => $submissionId,
                    'uploaderUserId' => (int)$user->getId(),
                    'submissionLocale' => (string)$submission->getData('locale'),
                    'fileStage' => SubmissionFile::SUBMISSION_FILE_PROOF,
                    'genreId' => (int)$data['genreId'],
                    'assocType' => Application::ASSOC_TYPE_PUBLICATION_FORMAT,
                    'assocId' => (int)$publicationFormat->getId(),
                    'mimetype' => $artifact['mediaType'],
                    'viewable' => false,
                    'directSalesPrice' => 0,
                    'salesType' => 'openAccess',
                    'name' => [
                        (string)$data['locale'] => $storedName,
                        (string)$submission->getData('locale') => $storedName,
                    ],
                ];

                $errors = Repo::submissionFile()->validate(
                    null,
                    $params,
                    $context->getSupportedSubmissionMetadataLocales(),
                    $submission->getData('locale')
                );
                if ($errors) {
                    abort(
                        422,
                        'The publication proof file failed OMP validation.'
                    );
                }

                $submissionFileId = Repo::submissionFile()->add(
                    Repo::submissionFile()->newDataObject($params)
                );
                $used = true;

                return [
                    'publicationFormatId' => (int)$publicationFormat->getId(),
                    'submissionFileId' => (int)$submissionFileId,
                    'unchanged' => false,
                    'formatApproved' => false,
                    'formatAvailable' => false,
                    'proofViewable' => false,
                ];
            });

            return response()->json(array_merge($receipt, [
                'protocol' => PublicationArtifactDocument::PROTOCOL,
                'submissionId' => $submissionId,
                'publicationId' => (int)$data['publicationId'],
                'format' => $data['format'],
                'mediaType' => $artifact['mediaType'],
                'artifactFileName' => $artifact['fileName'],
                'sha256' => $artifact['sha256'],
                'buildId' => $artifact['buildId'],
                'provenanceVerified' => true,
                'published' => false,
            ]));
        } catch (\Throwable $e) {
            $used = false;
            throw $e;
        } finally {
            fclose($temporary);
            if ($fileId !== null && !$used) {
                app()->get('file')->delete($fileId);
            }
        }
    }

    /** Public submission requirements; creation remains protected by native PKP author authorization. */
    public function submissionOptions(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $context = Application::get()->getRequest()->getContext();
        if (!$context || !$this->plugin->getEnabled($context->getId())) {
            return $this->error('context_required', 'An enabled publishing context is required.', 404);
        }
        $sections = [];
        foreach (Repo::section()->getCollector()->filterByContextIds([$context->getId()])->getMany() as $section) {
            if ($section->getIsInactive() || $section->getEditorRestricted()) continue;
            $sections[] = ['id' => (int)$section->getId(), 'label' => $section->getLocalizedTitle()];
        }
        $genres = [];
        $genreDao = DAORegistry::getDAO('GenreDAO');
        $enabledGenres = $genreDao->getEnabledByContextId($context->getId());
        while ($genre = $enabledGenres->next()) {
            $genres[] = ['id' => (int)$genre->getId(), 'label' => $genre->getLocalizedName()];
        }
        return response()->json([
            'protocol' => 'omi-direct-submission/1',
            'platform' => 'omp',
            'name' => $context->getLocalizedData('name'),
            'acceptingSubmissions' => !(bool)$context->getData('disableSubmissions'),
            'locales' => array_values($context->getSupportedSubmissionLocales()),
            'sections' => $sections,
            'genres' => $genres,
            'requirements' => $context->getLocalizedData('submissionChecklist'),
            'copyrightNotice' => $context->getLocalizedData('copyrightNotice'),
            'privacyStatement' => $context->getLocalizedData('privacyStatement'),
        ]);
    }

    public function capabilities(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) return $this->error('context_required', 'A press context is required.', 400);

        $reader = PluginRegistry::getPlugin('generic', 'htmlmonographfileplugin');
        $htmlAvailable = $reader && $reader->getEnabled($context->getId());

        return response()->json([
            'protocol' => 'omi-integration/1',
            'profile' => Omp35Adapter::PROFILE,
            'implementation' => [
                'name' => 'Open Manuscript Studio Integration for OMP',
                'version' => '1.7.0',
                'platform' => 'omp',
            ],
            'context' => (new Omp35Adapter())->mapContext($context, $request),
            'capabilities' => [
                'launch',
                'metadata.read',
                'editor.workflow.read',
                'editor.metadata.reconcile',
                'editor.volume-manifest.read',
                'editor.chapter-page-range.write',
                'volume.import.read',
                'contributors.read',
                'reviewers.read',
                'files.read',
                'files.content.read',
                'author.revision.write',
                'review.metadata.read',
                'review.files.read',
                'review.manuscript.read',
                'review.revision.write',
                'review.response.write',
                'review.form.read',
                'review.form.write',
                'review.forms.native',
                'review.files.scoped',
                'editor.publication-artifact.write',
                'publication.html.write',
                'publication.jats.write',
                ...(class_exists(\ZipArchive::class) ? ['publication.epub.write'] : []),
                'publication.pdf.write',
                'publication.provenance.verify',
            ],
            'publicationArtifacts' => [
                'protocol' => PublicationArtifactDocument::PROTOCOL,
                'provenance' => [
                    'required' => true,
                    'model' => PublicationArtifactDocument::BUILD_MODEL,
                    'version' => PublicationArtifactDocument::BUILD_VERSION,
                    'digest' => 'sha256',
                ],
                'formats' => PublicationArtifactDocument::formats((bool)$htmlAvailable),
                'authority' => [
                    'representation' => 'publicationFormat',
                    'formatApprovedByDefault' => false,
                    'formatAvailableByDefault' => false,
                    'proofViewableByDefault' => false,
                ],
            ],
            'plannedCapabilities' => [
                'review.recommendations.native',
            ],
        ]);
    }

    /**
     * Native authenticated read-only manifest for the current unpublished Production volume.
     * Never accepts a review assertion as a native editorial credential.
     */
    public function volumeImportManifest(IlluminateRequest $input): JsonResponse
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $user = $request->getUser();
        if (!$context || !$user || !$this->plugin->getEnabled($context->getId())) {
            return $this->error('editor_required', 'An enabled press and authenticated manager are required.', 403);
        }
        $adapter = new Omp35Adapter();
        $submissionId = (int)$input->route('submissionId');
        $submission = $adapter->getSubmission($submissionId, (int)$context->getId());
        if (!$submission) {
            return $this->error('submission_not_found', 'Volume not found.', 404);
        }
        $stages = Repo::user()->getAccessibleWorkflowStages($user->getId(), $context->getId(), $submission);
        if (!array_intersect($stages[WORKFLOW_STAGE_ID_PRODUCTION] ?? [], [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN])) {
            return $this->error('editor_required', 'Manager access to this volume in Production is required.', 403);
        }
        $publication = Repo::publication()->get((int)$submission->getData('currentPublicationId'));
        if (!$publication || (int)$submission->getData('stageId') !== WORKFLOW_STAGE_ID_PRODUCTION
            || (int)$publication->getData('status') !== Submission::STATUS_QUEUED) {
            return $this->error('volume_not_ready', 'The volume must be the current unpublished Production version.', 409);
        }
        return $this->buildVolumeManifest($context, $submission, $publication, false);
    }

    private function buildVolumeManifest(object $context, object $submission, object $publication, bool $signed): JsonResponse
    {
        $request = Application::get()->getRequest();
        $adapter = new Omp35Adapter();
        $submissionId = (int)$submission->getId();
        $publicationId = (int)$publication->getId();
        $volume = $adapter->mapSubmission($submission);
        $chapters = $adapter->mapVolumeChapters($publicationId);
        $contributors = $adapter->mapContributors($submission);
        foreach ($contributors as &$contributor) {
            unset($contributor['email']);
        }
        unset($contributor);
        $chapterIds = array_column($chapters, 'externalId');
        $sources = Omp35Adapter::volumeSourceDescriptors($adapter->mapFiles($submission), $chapterIds);
        if ($signed) {
            foreach ($sources as &$source) {
                $source['contentPath'] = 'volume-import/files/' . rawurlencode($source['externalId']) . '/content';
            }
            unset($source);
        }
        $current = $adapter->getSubmission($submissionId, (int)$context->getId());
        $currentPublication = $current ? Repo::publication()->get((int)$current->getData('currentPublicationId')) : null;
        if (!$current || !$currentPublication || (int)$currentPublication->getId() !== $publicationId
            || (string)$publicationId !== $volume['publicationExternalId']
            || (int)$current->getData('stageId') !== WORKFLOW_STAGE_ID_PRODUCTION
            || (int)$currentPublication->getData('status') !== Submission::STATUS_QUEUED) {
            return $this->error('volume_changed', 'Volume changed during manifest projection; reload.', 409);
        }
        return response()->json([
            'protocol' => 'omi-integration/1',
            'profile' => Omp35Adapter::PROFILE,
            'installationId' => $this->plugin->getInstallationId($context->getId(), $request),
            'context' => $adapter->mapContext($context, $request),
            'volume' => $volume,
            'workType' => (int)$submission->getData('workType') === Submission::WORK_TYPE_EDITED_VOLUME
                ? 'edited-volume' : 'authored-work',
            'doi' => $publication->getDoi(),
            'publicationMatter' => $adapter->mapVolumePublicationMatter($context, $publication),
            'chapters' => $chapters,
            'contributors' => $contributors,
            'sources' => $sources,
            'binaryTransfer' => ['available' => $signed],
        ])->header('Cache-Control', 'private, no-store');
    }

    private function authorizeVolumeImport(IlluminateRequest $input): array|JsonResponse
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context || !$this->plugin->getEnabled($context->getId())) {
            return $this->error('context_required', 'An enabled press is required.', 403);
        }
        $authorization = trim((string)$input->header('Authorization', ''));
        if (!preg_match('/^OMI\\s+([A-Za-z0-9_-]+)\\.([A-Za-z0-9_-]+)$/', $authorization, $matches)) {
            return $this->error('authentication_required', 'A signed volume assertion is required.', 401);
        }
        $secret = (string)$this->plugin->getSetting($context->getId(), 'sharedSecret');
        $claims = $secret !== '' ? LaunchToken::verify($matches[1], $matches[2], $secret, $context->getId()) : null;
        if (!$claims || ($claims['profile'] ?? null) !== Omp35Adapter::PROFILE
            || ($claims['actorMode'] ?? null) !== 'volume'
            || ($claims['scope'] ?? null) !== ['volume.import.read']
            || ($claims['installationId'] ?? null) !== $this->plugin->getInstallationId($context->getId(), $request)
            || ($claims['context']['type'] ?? null) !== 'press'
            || ($claims['context']['path'] ?? null) !== $context->getPath()
            || isset($claims['submission']) || isset($claims['component'])
            || !is_string($claims['nonce'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $claims['nonce'])
            || (int)$claims['exp'] <= (int)$claims['iat']
            || (int)$claims['exp'] - (int)$claims['iat'] > 300) {
            return $this->error('invalid_assertion', 'Invalid or expired volume assertion.', 401);
        }
        $submissionId = (int)($claims['volume']['submissionId'] ?? 0);
        $publicationId = (int)($claims['volume']['publicationId'] ?? 0);
        $actorId = (int)($claims['actor']['externalId'] ?? 0);
        if ($submissionId < 1 || $publicationId < 1 || $actorId < 1) {
            return $this->error('invalid_assertion', 'Missing volume or actor identity.', 401);
        }
        $actor = Repo::user()->get($actorId);
        $target = $this->plugin->volumeImportTarget($context, $actor, $submissionId, $publicationId);
        if (!$target) {
            return $this->error('volume_forbidden', 'Manager access or current unpublished volume changed.', 403);
        }
        return [$context, $target[0], $target[1]];
    }

    public function signedVolumeManifest(IlluminateRequest $input): JsonResponse
    {
        $authorized = $this->authorizeVolumeImport($input);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$context, $submission, $publication] = $authorized;
        return $this->buildVolumeManifest($context, $submission, $publication, true);
    }

    public function signedVolumeFile(IlluminateRequest $input): BinaryFileResponse|JsonResponse
    {
        $authorized = $this->authorizeVolumeImport($input);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$context, $submission, $publication] = $authorized;
        $adapter = new Omp35Adapter();
        $chapterIds = array_column($adapter->mapVolumeChapters((int)$publication->getId()), 'externalId');
        $sources = Omp35Adapter::volumeSourceDescriptors($adapter->mapFiles($submission), $chapterIds);
        $id = (int)$input->route('submissionFileId');
        $candidate = null;
        foreach ($sources as $source) {
            if ((int)$source['externalId'] === $id) {
                $candidate = $source;
                break;
            }
        }
        if (!$candidate) return $this->error('file_forbidden', 'File is not an eligible source of this volume.', 404);
        $submissionFile = Repo::submissionFile()->get($id, (int)$submission->getId());
        if (!$submissionFile || (int)$submissionFile->getData('submissionId') !== (int)$submission->getId()
            || (int)$submissionFile->getData('fileStage') !== (int)$candidate['stage']
            || (int)$submissionFile->getData('genreId') !== (int)$candidate['genreExternalId']
            || (string)$submissionFile->getData('chapterId') !== (string)$candidate['chapterExternalId']
            || (string)$submissionFile->getData('revision') !== (string)$candidate['revision']) {
            return $this->error('file_changed', 'Source changed; reload the volume manifest.', 409);
        }
        $fileId = (int)$submissionFile->getData('fileId');
        $stored = $fileId > 0 ? app()->get('file')->get($fileId) : null;
        $root = realpath((string)Config::getVar('files', 'files_dir'));
        $path = $root && $stored && !empty($stored->path) ? realpath($root . '/' . ltrim((string)$stored->path, '/')) : false;
        if (!$path || !str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
            || !is_file($path) || !is_readable($path)) {
            return $this->error('file_not_found', 'Stored source is unavailable.', 404);
        }
        $size = filesize($path);
        if ($size === false || $size > 32 * 1024 * 1024) {
            return $this->error('source_too_large', 'Volume source exceeds 32 MiB.', 413);
        }
        // Revalidate the actor, current publication and press immediately before responding.
        $rechecked = $this->authorizeVolumeImport($input);
        if ($rechecked instanceof JsonResponse) return $rechecked;
        return response()->file($path, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => "attachment; filename*=UTF-8''" . rawurlencode((string)$candidate['name']),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function submission(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $authorized = $this->authorizeSubmissionRequest($illuminateRequest);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$claims, $submissionId, $context] = $authorized;
        if (!$this->hasAnyScope($claims, ['metadata.read', 'review.metadata.read'])) {
            return $this->error('insufficient_scope', 'The signed assertion does not grant monograph metadata access.', 403);
        }

        $adapter = new Omp35Adapter();
        $submission = $adapter->getSubmission($submissionId, $context->getId());
        if (!$submission) return $this->error('submission_not_found', 'Monograph submission not found.', 404);
        $mappedSubmission = $adapter->mapSubmission($submission);
        $mappedSubmission['publicationLanguages'] = $this->workflowPublicationLanguages($context, $submission);
        if (($claims['actorMode'] ?? '') === 'review') {
            $reviewAssignment = $this->reviewAssignmentForClaims($claims, $submissionId);
            $componentId = (int)($claims['component']['externalId'] ?? 0);
            if (!$reviewAssignment || $componentId < 1) {
                return $this->error('review_component_forbidden', 'The signed review does not identify one authorized OMP chapter.', 403);
            }
            $mappedSubmission = $adapter->mapReviewArticle($submission, $componentId);
            if ($mappedSubmission === null) {
                return $this->error('review_component_not_found', 'The assigned OMP chapter is not available.', 404);
            }
        }

        $actor = null;
        $actorId = (int)($claims['actor']['externalId'] ?? 0);
        if ($actorId > 0) {
            $actorUser = Repo::user()->get($actorId);
            if ($actorUser) {
                $actor = [
                    'externalId' => (string)$actorId,
                    'email' => (string)$actorUser->getEmail(),
                    'fullName' => (string)$actorUser->getFullName(),
                ];
            }
        }

        return response()->json([
            'protocol' => 'omi-integration/1',
            'profile' => Omp35Adapter::PROFILE,
            'installationId' => $this->plugin->getInstallationId($context->getId(), Application::get()->getRequest()),
            'context' => $adapter->mapContext($context, Application::get()->getRequest()),
            'submission' => $mappedSubmission,
            'actor' => $actor,
        ]);
    }

    public function contributors(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $authorized = $this->authorizeSubmissionRequest($illuminateRequest);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$claims, $submissionId, $context] = $authorized;
        if (!$this->hasScope($claims, 'contributors.read')) {
            return $this->error('insufficient_scope', 'The signed assertion does not grant contributor identity access.', 403, ['required' => 'contributors.read']);
        }
        $adapter = new Omp35Adapter();
        $submission = $adapter->getSubmission($submissionId, $context->getId());
        if (!$submission) return $this->error('submission_not_found', 'Monograph submission not found.', 404);
        return response()->json([
            'protocol' => 'omi-integration/1',
            'profile' => Omp35Adapter::PROFILE,
            'submissionExternalId' => (string)$submissionId,
            'contributors' => $adapter->mapContributors($submission),
        ]);
    }

    public function reviewers(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $authorized = $this->authorizeSubmissionRequest($illuminateRequest);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$claims, $submissionId, $context] = $authorized;
        if (!$this->hasScope($claims, 'review.identity.read')) {
            return $this->error('insufficient_scope', 'The signed assertion does not grant access to reviewer identities.', 403, ['required' => 'review.identity.read']);
        }

        $userGroupIds = array_merge(Repo::userGroup()->getArrayIdByRoleId(Role::ROLE_ID_REVIEWER, $context->getId()), Repo::userGroup()->getArrayIdByRoleId(Role::ROLE_ID_ASSISTANT, $context->getId()));
        $reviewers = [];
        if ($userGroupIds) {
            $users = Repo::user()->getCollector()
                ->filterByContextIds([$context->getId()])
                ->filterByUserGroupIds($userGroupIds)
                ->getMany();
            foreach ($users as $user) {
                $email = trim((string)$user->getEmail());
                if ($email === '') continue;
                $reviewers[] = [
                    'externalId' => (string)$user->getId(),
                    'email' => $email,
                    'fullName' => (string)$user->getFullName(),
                ];
            }
        }
        usort($reviewers, static fn (array $a, array $b): int => strcasecmp($a['fullName'], $b['fullName']));
        return response()->json([
            'protocol' => 'omi-integration/1',
            'profile' => Omp35Adapter::PROFILE,
            'submissionExternalId' => (string)$submissionId,
            'reviewers' => $reviewers,
        ]);
    }

    public function files(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $authorized = $this->authorizeSubmissionRequest($illuminateRequest);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$claims, $submissionId, $context] = $authorized;
        if (!$this->hasAnyScope($claims, ['files.read', 'review.files.read'])) {
            return $this->error('insufficient_scope', 'The signed assertion does not grant file access.', 403);
        }
        $adapter = new Omp35Adapter();
        $submission = $adapter->getSubmission($submissionId, $context->getId());
        if (!$submission) return $this->error('submission_not_found', 'Monograph submission not found.', 404);

        $files = $adapter->mapFiles($submission);
        if (($claims['actorMode'] ?? '') === 'review') {
            if (!$this->hasScope($claims, 'review.files.read')) {
                return $this->error('insufficient_scope', 'Reviewer file access requires review.files.read.', 403);
            }
            $reviewAssignment = $this->reviewAssignmentForClaims($claims, $submissionId);
            if (!$reviewAssignment) {
                return $this->error('review_assignment_forbidden', 'The review assignment is not valid for the current OMP review round.', 403);
            }
            $componentId = (int)($claims['component']['externalId'] ?? 0);
            $files = array_values(array_filter(
                $files,
                fn (array $file): bool => $this->reviewFileAllowed(
                    $reviewAssignment,
                    (int)($file['externalId'] ?? 0),
                    $componentId
                )
            ));
        } elseif (($claims['actorMode'] ?? '') === 'author') {
            $allowedFileStages = $this->authorReadableFileStages(
                $claims,
                $submission,
                $context
            );
            if ($allowedFileStages === null) {
                return $this->error(
                    'author_file_access_forbidden',
                    'The signed author is not assigned to this monograph workflow.',
                    403
                );
            }
            $files = array_values(array_filter(
                $files,
                static fn (array $file): bool => in_array(
                    (int)($file['stage'] ?? 0),
                    $allowedFileStages,
                    true
                )
            ));
        }

        $files = array_map(function (array $file): array {
            $file['contentPath'] = 'files/' . rawurlencode((string)$file['externalId']) . '/content';
            return $file;
        }, $files);

        return response()->json([
            'protocol' => 'omi-integration/1',
            'profile' => Omp35Adapter::PROFILE,
            'submissionExternalId' => (string)$submissionId,
            'files' => $files,
            'binaryTransfer' => ['available' => true, 'authorization' => 'OMI launch assertion'],
        ]);
    }

    public function reviewForm(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $authorized = $this->authorizeSubmissionRequest($illuminateRequest);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$claims, $submissionId] = $authorized;
        if (($claims['actorMode'] ?? '') !== 'review' || !$this->hasScope($claims, 'review.form.read')) {
            return $this->error('insufficient_scope', 'Reviewer form access requires review.form.read.', 403, ['required' => 'review.form.read']);
        }
        $assignment = $this->reviewAssignmentForClaims($claims, $submissionId);
        if (!$assignment) return $this->error('review_assignment_forbidden', 'The review assignment is not valid for the current OMP review round.', 403);

        $formId = (int)$assignment->getData('reviewFormId');
        if ($formId < 1) {
            return response()->json([
                'protocol' => 'omi-integration/1',
                'profile' => Omp35Adapter::PROFILE,
                'submissionExternalId' => (string)$submissionId,
                'reviewAssignmentExternalId' => (string)$assignment->getId(),
                'reviewForm' => null,
            ]);
        }

        /** @var \PKP\reviewForm\ReviewFormElementDAO $elementDao */
        $elementDao = DAORegistry::getDAO('ReviewFormElementDAO');
        /** @var \PKP\reviewForm\ReviewFormResponseDAO $responseDao */
        $responseDao = DAORegistry::getDAO('ReviewFormResponseDAO');
        $responseValues = $responseDao->getReviewReviewFormResponseValues($assignment->getId());
        $elements = [];
        $result = $elementDao->getByReviewFormId($formId);
        while ($element = $result->next()) {
            $elementId = (int)$element->getId();
            $elements[] = [
                'externalId' => (string)$elementId,
                'type' => $this->reviewFormElementType((int)$element->getElementType()),
                'question' => $this->reviewFormPlainText($element->getLocalizedQuestion()),
                'description' => $this->reviewFormPlainText($element->getLocalizedDescription()),
                'required' => (bool)$element->getRequired(),
                'authorVisible' => (bool)$element->getIncluded(),
                'options' => $this->reviewFormOptions($element->getLocalizedPossibleResponses()),
                'localizations' => $this->reviewFormLocalizations($element),
                'value' => array_key_exists($elementId, $responseValues) ? $responseValues[$elementId] : null,
            ];
        }

        return response()->json([
            'protocol' => 'omi-integration/1',
            'profile' => Omp35Adapter::PROFILE,
            'submissionExternalId' => (string)$submissionId,
            'reviewAssignmentExternalId' => (string)$assignment->getId(),
            'reviewForm' => [
                'externalId' => (string)$formId,
                'elements' => $elements,
            ],
        ]);
    }

    public function fileContent(IlluminateRequest $illuminateRequest): BinaryFileResponse|JsonResponse
    {
        $routeFileId = $illuminateRequest->route('submissionFileId');
        if (!is_scalar($routeFileId) || !ctype_digit((string)$routeFileId)) {
            return $this->error('invalid_file_id', 'Invalid submission file ID.', 400);
        }
        $submissionFileId = (int)$routeFileId;
        if ($submissionFileId < 1) return $this->error('invalid_file_id', 'Invalid submission file ID.', 400);

        $authorized = $this->authorizeSubmissionRequest($illuminateRequest);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$claims, $submissionId] = $authorized;
        if (!$this->hasAnyScope($claims, ['files.read', 'review.files.read'])) {
            return $this->error('insufficient_scope', 'The signed assertion does not grant file access.', 403);
        }

        if (($claims['actorMode'] ?? '') === 'review') {
            if (!$this->hasScope($claims, 'review.files.read')) {
                return $this->error('insufficient_scope', 'Reviewer file access requires review.files.read.', 403);
            }
            $reviewAssignment = $this->reviewAssignmentForClaims($claims, $submissionId);
            $componentId = (int)($claims['component']['externalId'] ?? 0);
            if (!$reviewAssignment || !$this->reviewFileAllowed($reviewAssignment, $submissionFileId, $componentId)) {
                return $this->error('file_not_available_for_review', 'This file is not available to the current review assignment.', 403);
            }
        }

        $submissionFile = Repo::submissionFile()->get($submissionFileId, $submissionId);
        if (!$submissionFile || (int)$submissionFile->getData('submissionId') !== $submissionId) {
            return $this->error('file_not_found', 'Submission file not found.', 404);
        }

        if (($claims['actorMode'] ?? '') === 'author') {
            $context = Application::get()->getRequest()->getContext();
            $submission = $context
                ? Repo::submission()->get($submissionId, $context->getId())
                : null;
            $allowedFileStages = $submission && $context
                ? $this->authorReadableFileStages($claims, $submission, $context)
                : null;
            if (
                $allowedFileStages === null ||
                !in_array(
                    (int)$submissionFile->getData('fileStage'),
                    $allowedFileStages,
                    true
                )
            ) {
                return $this->error(
                    'file_not_available_for_author',
                    'This file is not available to the signed author in the native OMP workflow.',
                    403
                );
            }
        }

        $fileId = (int)$submissionFile->getData('fileId');
        $storedFile = $fileId > 0 ? app()->get('file')->get($fileId) : null;
        if (!$storedFile || empty($storedFile->path)) return $this->error('file_not_found', 'Stored file content not found.', 404);

        $absolutePath = rtrim((string)Config::getVar('files', 'files_dir'), '/') . '/' . ltrim((string)$storedFile->path, '/');
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return $this->error('file_not_readable', 'Stored file content is not readable.', 404);
        }

        $context = Application::get()->getRequest()->getContext();
        $name = (string)($submissionFile->getData('originalFileName') ?? $submissionFile->getData('name', $context?->getPrimaryLocale()) ?? ('submission-file-' . $submissionFileId));
        $mediaType = (string)($submissionFile->getData('mimetype') ?? 'application/octet-stream');
        return response()->file($absolutePath, [
            'Content-Type' => $mediaType,
            'Content-Disposition' => "attachment; filename*=UTF-8''" . rawurlencode($name),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Read-only bibliographic grouping for the participant's signed work statement. */
    public function workContexts(IlluminateRequest $input): JsonResponse
    {
        $context = Application::get()->getRequest()->getContext();
        if (!$context) return $this->error('context_required', 'A publishing context is required.', 400);
        if ($error = $this->authorizeServiceRequest($input, (int)$context->getId())) return $error;
        $tasks = $input->input('tasks');
        if (!is_array($tasks) || !array_is_list($tasks) || count($tasks) < 1 || count($tasks) > 100) return $this->error('invalid_tasks', 'Provide at most 100 bound tasks.', 422);
        $result = [];
        foreach ($tasks as $task) {
            if (!is_array($task) || !is_string($task['assignmentToken'] ?? null) || strlen($task['assignmentToken']) > 32000 || !preg_match('/^[a-f0-9-]{36}$/i', (string)($task['localId'] ?? ''))) return $this->error('invalid_task', 'A bound task is required.', 422);
            $parts = explode('.', $task['assignmentToken']);
            if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', 'workflow-assignment/1.' . $parts[0], (string)$this->plugin->getSetting($context->getId(), 'sharedSecret')), $parts[1])) return $this->error('invalid_assignment_token', 'Invalid task binding.', 403);
            $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);
            $binding = $decoded === false ? null : json_decode($decoded, true);
            $submissionId = (int)($task['submissionExternalId'] ?? 0);
            if (!is_array($binding) || ($binding['contextId'] ?? null) !== (int)$context->getId() || ($binding['submissionId'] ?? null) !== $submissionId || ($binding['assignmentId'] ?? null) !== 'workflow-' . $task['localId'] || !in_array($binding['assignmentType'] ?? '', ['LANGUAGE_REVIEW', 'TRANSLATION'], true)) return $this->error('assignment_mismatch', 'The task belongs to another context, submission or role.', 403);
            $submission = Repo::submission()->get($submissionId, (int)$context->getId());
            $participant = Repo::user()->get((int)($binding['participantId'] ?? 0));
            $publication = $submission ? Repo::publication()->get((int)($binding['publicationId'] ?? 0), $submissionId) : null;
            $allowed = $submission && $participant && !$participant->getData('disabled') && $this->workflowEditorAllowed($context, $submission, (int)($binding['editorId'] ?? 0));
            $result[] = ['localId' => $task['localId'], 'context' => $allowed ? $this->workAccountingContext($context, $submission, $publication, (int)($binding['chapterId'] ?? 0)) : null];
        }
        return response()->json(['protocol' => 'omi-work-contexts/1', 'contexts' => $result]);
    }

    private function accountingText(mixed $value, string $locale): string
    {
        if (is_array($value)) $value = $value[$locale] ?? $value[array_key_first($value)] ?? '';
        return is_scalar($value) ? mb_substr(trim(html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 500) : '';
    }

    private function workAccountingContext(object $context, object $submission, ?object $publication, int $chapterId = 0): ?array
    {
        if (!$publication) return null;
        $locale = (string)$submission->getData('locale');
        $title = $this->accountingText($publication->getData('title'), $locale);
        $unitId = 'volume:' . $submission->getId() . ':' . $publication->getId(); $kind = 'volume';
        $containerId = (string)$publication->getId(); $containerTitle = $title;
        if ($chapterId > 0) {
            $chapter = DAORegistry::getDAO('ChapterDAO')->getChapter($chapterId, (int)$publication->getId());
            if (!$chapter) return null;
            $unitId = 'chapter:' . $chapterId . ':' . $publication->getId(); $kind = 'chapter';
            $title = $this->accountingText($chapter->getData('title'), $locale);
        }
        return ['platform' => 'omp', 'contextId' => (string)$context->getId(), 'contextName' => $this->accountingText($context->getData('name'), $locale),
            'submissionId' => (string)$submission->getId(), 'publicationId' => (string)$publication->getId(),
            'unitId' => $unitId, 'unitKind' => $kind, 'title' => $title,
            'containerId' => $containerId, 'containerKind' => 'volume', 'containerTitle' => $containerTitle];
    }

    public function workflowAssignment(IlluminateRequest $input): JsonResponse
    {
        $context = Application::get()->getRequest()->getContext();
        if (!$context) return $this->error('context_required', 'A press context is required.', 400);
        if ($error = $this->authorizeServiceRequest($input, $context->getId())) return $error;
        $submission = Repo::submission()->get((int)$input->input('submissionExternalId'), $context->getId());
        if (!$submission) return $this->error('submission_not_found', 'Submission not found.', 404);
        $editorId = (int)$input->input('editorExternalId');
        if (!$this->workflowEditorAllowed($context, $submission, $editorId)) return $this->error('editor_required', 'Current editorial access to this submission is required.', 403);
        $id = (string)$input->input('assignmentId');
        $type = (string)$input->input('assignmentType');
        if (!preg_match('/^[a-f0-9-]{36}$/i', $id) || !in_array($type, ['SCIENTIFIC_REVIEW', 'LANGUAGE_REVIEW', 'TRANSLATION'], true)) {
            return $this->error('invalid_assignment', 'A valid task identifier and role are required.', 422);
        }
        if ($type === 'TRANSLATION') {
            try {
                $sourceLanguage = $this->translationLanguage((string)$input->input('sourceLanguage'));
                $targetLanguage = $this->translationLanguage((string)$input->input('targetLanguage'));
            } catch (\InvalidArgumentException) { return $this->error('invalid_languages', 'Valid translation languages are required.', 422); }
            if ($sourceLanguage === $targetLanguage) return $this->error('invalid_languages', 'Translation requires distinct source and target languages.', 422);
            $supportedTargets = array_map($this->translationLanguage(...), $context->getSupportedSubmissionMetadataLocales());
            if (!in_array($targetLanguage, $supportedTargets, true)) return $this->error('unsupported_target_language', 'Enable the translation target metadata language in OMP first.', 422);
        }
        $participant = Repo::user()->getByEmail(strtolower(trim((string)$input->input('reviewerEmail'))));
        if (!$participant || $participant->getData('disabled')) return $this->error('participant_not_found', 'An active OMP participant account is required.', 422);
        $groupIds = Repo::userGroup()->getArrayIdByRoleId(Role::ROLE_ID_REVIEWER, $context->getId());
        if ($type !== 'SCIENTIFIC_REVIEW') $groupIds = array_merge($groupIds, Repo::userGroup()->getArrayIdByRoleId(Role::ROLE_ID_ASSISTANT, $context->getId()));
        if (!$groupIds) return $this->error('participant_not_available', 'No eligible participant group is available.', 403);
        $member = Repo::user()->getCollector()->filterByContextIds([$context->getId()])->filterByUserGroupIds($groupIds)->getMany()->first(fn ($user) => (int)$user->getId() === (int)$participant->getId());
        if (!$member) return $this->error('participant_not_available', 'Select an eligible participant from this press.', 403);
        $chapterId = (int)$input->input('componentExternalId', 0);
        if ($chapterId > 0 && $type === 'TRANSLATION') return $this->error('volume_translation_required', 'Translation metadata tasks must be assigned from the whole-volume workspace.', 422);
        if ($chapterId > 0 && !DAORegistry::getDAO('ChapterDAO')->getChapter($chapterId, (int)$submission->getCurrentPublication()?->getId())) return $this->error('chapter_mismatch', 'The chapter does not belong to the current publication.', 403);
        return DB::transaction(function () use ($input, $context, $submission, $editorId, $participant, $id, $type, $chapterId) {
            DB::table('submissions')->where('submission_id', $submission->getId())->lockForUpdate()->first();
            $submission = Repo::submission()->get((int)$submission->getId(), $context->getId());
            if (!$this->workflowEditorAllowed($context, $submission, $editorId)) return $this->error('editor_required', 'Editorial permissions changed.', 403);
            $stage = (int)$submission->getData('stageId');
            $round = null;
            if (in_array($stage, [WORKFLOW_STAGE_ID_INTERNAL_REVIEW, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW], true)) $round = DAORegistry::getDAO('ReviewRoundDAO')->getLastReviewRoundBySubmissionId($submission->getId(), $stage);
            if ($input->input('expectedStageId') !== null && (int)$input->input('expectedStageId') !== $stage) return $this->error('review_stage_changed', 'The workflow stage changed. Create a new task.', 409);
            if ($input->input('expectedReviewRoundExternalId') !== null && (int)$input->input('expectedReviewRoundExternalId') !== ($round ? (int)$round->getId() : 0)) return $this->error('review_round_changed', 'The native review round changed. Create a new task.', 409);
            if ($input->input('expectedReviewRound') !== null && (int)$input->input('expectedReviewRound') !== ($round ? (int)$round->getRound() : 1)) return $this->error('review_round_changed', 'The current OMP round differs from this task. Create a new task.', 409);
            $publicationLanguages = $this->workflowPublicationLanguages($context, $submission);
            if ($type === 'TRANSLATION' && $input->input('publicationLanguages') !== null) {
                $requested = $input->input('publicationLanguages');
                if (!is_array($requested) || count($requested) > 32) return $this->error('invalid_publication_languages', 'Specify full-text publication languages.', 422);
                try { foreach ($requested as $language) $publicationLanguages[] = $this->translationLanguage((string)$language); }
                catch (\InvalidArgumentException) { return $this->error('invalid_publication_languages', 'Valid full-text publication languages are required.', 422); }
                $publicationLanguages = array_values(array_unique($publicationLanguages));
                $publication = $submission->getCurrentPublication();
                $this->plugin->updateSetting($context->getId(), 'translationPublicationLanguages_' . ($publication ? $publication->getId() : $submission->getId()), $publicationLanguages, 'object');
            }
            $externalId = 'workflow-' . $id;
            if ($type === 'SCIENTIFIC_REVIEW') {
                if (!$round) return $this->error('review_round_required', 'Scientific peer review requires an active internal or external review round.', 409);
                $authorRoles = Repo::user()->getAccessibleWorkflowStages($participant->getId(), $context->getId(), $submission);
                foreach ($authorRoles as $roles) if (in_array(Role::ROLE_ID_AUTHOR, $roles, true)) return $this->error('reviewer_is_author', 'An author cannot review their own submission.', 403);
                $assignment = Repo::reviewAssignment()->getCollector()->filterByReviewRoundIds([$round->getId()])->filterByReviewerIds([$participant->getId()])->getMany()->first();
                if ($assignment) {
                    if ((int)$assignment->getReviewMethod() !== ReviewAssignment::SUBMISSION_REVIEW_METHOD_DOUBLEANONYMOUS || !$this->reviewAssignmentAllowsFileWrite($assignment)) {
                        return $this->error('review_assignment_conflict', 'The existing review assignment is not writable double-anonymous peer review.', 409);
                    }
                    $externalId = (string)$assignment->getId();
                } else {
                    $params = ['submissionId' => (int)$submission->getId(), 'reviewerId' => (int)$participant->getId(),
                        'reviewRoundId' => (int)$round->getId(), 'round' => (int)$round->getRound(), 'stageId' => $stage,
                        'reviewMethod' => ReviewAssignment::SUBMISSION_REVIEW_METHOD_DOUBLEANONYMOUS, 'dateAssigned' => Core::getCurrentDate()];
                    $reviewWeeks = (int)$context->getData('numWeeksPerReview');
                    $responseWeeks = (int)$context->getData('numWeeksPerResponse');
                    $params['dateDue'] = date('Y-m-d 23:59:59', strtotime('+' . ($reviewWeeks > 0 ? $reviewWeeks : 4) . ' weeks'));
                    $params['dateResponseDue'] = date('Y-m-d 23:59:59', strtotime('+' . ($responseWeeks > 0 ? $responseWeeks : 3) . ' weeks'));
                    $publication = $submission->getCurrentPublication();
                    $seriesId = $publication ? (int)$publication->getData('seriesId') : 0;
                    $series = $seriesId ? Repo::section()->get($seriesId, $context->getId()) : null;
                    if ($series && $series->getReviewFormId()) $params['reviewFormId'] = (int)$series->getReviewFormId();
                    if (Repo::reviewAssignment()->validate(null, $params, $context)) return $this->error('assignment_validation_failed', 'OMP rejected the review assignment.', 422);
                    $externalId = (string)Repo::reviewAssignment()->add(Repo::reviewAssignment()->newDataObject($params));
                }
            }
            $binding = ['contextId' => (int)$context->getId(), 'submissionId' => (int)$submission->getId(),
                'editorId' => $editorId, 'participantId' => (int)$participant->getId(), 'assignmentId' => $externalId,
                'assignmentType' => $type, 'stageId' => $stage, 'chapterId' => $chapterId ?: null,
                'publicationId' => $submission->getCurrentPublication()?->getId(),
                'publicationLanguages' => $publicationLanguages,
                ...($type === 'TRANSLATION' ? ['sourceLanguage' => $this->translationLanguage((string)$input->input('sourceLanguage')), 'targetLanguage' => $this->translationLanguage((string)$input->input('targetLanguage'))] : []), 'reviewRoundId' => $round ? (int)$round->getId() : null,
                'reviewRound' => $round ? (int)$round->getRound() : 1];
            if ($type !== 'SCIENTIFIC_REVIEW') $binding['workContext'] = $this->workAccountingContext($context, $submission, $submission->getCurrentPublication(), $chapterId);
            $payload = rtrim(strtr(base64_encode(json_encode($binding, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
            $signature = hash_hmac('sha256', 'workflow-assignment/1.' . $payload, (string)$this->plugin->getSetting($context->getId(), 'sharedSecret'));
            return response()->json(['externalAssignmentId' => $externalId, 'reviewRound' => $binding['reviewRound'],
                'publicationLanguages' => $publicationLanguages, 'anonymityMode' => $type === 'SCIENTIFIC_REVIEW' ? 'double_blind' : 'open', 'assignmentToken' => $payload . '.' . $signature,
                ...($type === 'SCIENTIFIC_REVIEW' ? ['recommendations' => $this->reviewerRecommendationCatalog($context, Repo::reviewAssignment()->get((int)$externalId, $submission->getId())), 'reviewForm' => $this->workflowReviewForm(Repo::reviewAssignment()->get((int)$externalId, $submission->getId()))] : [])]);
        });
    }


    private function translationLanguage(string $value): string
    {
        if (!preg_match('/^([a-z]{2,8})(?:[-_]([a-z]{4}))?(?:[-_](?:[a-z]{2}|[0-9]{3}))?$/i', trim($value), $parts)) throw new \InvalidArgumentException('Invalid translation language.');
        return strtolower($parts[1]) . (!empty($parts[2]) ? '-' . ucfirst(strtolower($parts[2])) : '');
    }

    /** Actual/planned full-text languages, never the metadata-locale list. */
    private function workflowPublicationLanguages(object $context, object $submission): array
    {
        $publication = $submission->getCurrentPublication();
        $id = $publication ? (int)$publication->getId() : (int)$submission->getId();
        $languages = [$this->translationLanguage((string)$submission->getData('locale'))];
        foreach ((array)$this->plugin->getSetting($context->getId(), 'translationPublicationLanguages_' . $id) as $language) $languages[] = $this->translationLanguage((string)$language);
        if ($publication) {
            // OMP formats localize their names, not their text language. Only an
            // explicitly language-tagged proof for a current format adds an actual language.
            $formats = Application::getRepresentationDAO()->getByPublicationId((int)$publication->getId(), (int)$context->getId());
            $formatIds = array_map(static fn ($format) => (int)$format->getId(), $formats);
            if ($formatIds) foreach (Repo::submissionFile()->getCollector()->filterBySubmissionIds([$submission->getId()])->filterByFileStages([SubmissionFile::SUBMISSION_FILE_PROOF])->getMany() as $file) {
                if ((int)$file->getData('assocType') !== Application::ASSOC_TYPE_PUBLICATION_FORMAT || !in_array((int)$file->getData('assocId'), $formatIds, true)) continue;
                $language = $file->getData('language');
                if (is_string($language) && $language !== '') {
                    try { $languages[] = $this->translationLanguage($language); } catch (\InvalidArgumentException) { /* Free-text supplemental language labels are not publication locales. */ }
                }
            }
        }
        return array_values(array_unique($languages));
    }


    private function writeWorkflowTranslationMetadata(object $context, object $submission, array $binding, array $translation): array
    {
        $publication = $submission->getCurrentPublication();
        if (!$publication || (int)$publication->getId() !== (int)($binding['publicationId'] ?? 0)) throw new HttpResponseException($this->error('publication_changed', 'The OMP publication version changed. Reconnect the translation task.', 409));
        if ((int)$publication->getData('status') !== Submission::STATUS_QUEUED || !Repo::submission()->canEditPublication($submission->getId(), (int)$binding['editorId'])) throw new HttpResponseException($this->error('publication_not_editable', 'Translation metadata can be returned only to an editable unpublished OMP version.', 403));
        $target = $this->translationLanguage((string)$binding['targetLanguage']);
        $locales = $context->getSupportedSubmissionMetadataLocales();
        $locale = null;
        foreach ($locales as $candidate) if ($this->translationLanguage((string)$candidate) === $target) { $locale = (string)$candidate; break; }
        if ($locale === null) throw new HttpResponseException($this->error('translation_locale_disabled', 'Enable the target metadata language in OMP before returning this translation.', 422));
        $adapter = new Omp35Adapter();
        $params = MetadataReconciliation::patch($publication, $locale,
            ['title' => $translation['title'], 'abstract' => $translation['abstract'], 'keywords' => $translation['keywords']],
            $locales, $adapter->reconciliationKeywords($publication, (string)$submission->getData('locale')));
        $params['id'] = (int)$publication->getId(); $params['submissionId'] = (int)$submission->getId();
        if (Repo::publication()->validate($publication, $params, $submission, $context)) throw new HttpResponseException($this->error('translation_metadata_invalid', 'OMP rejected the translated metadata.', 422));
        Repo::publication()->edit($publication, $params);
        return ['locale' => $locale, 'targetLanguage' => $target, 'publicationExternalId' => (string)$publication->getId(), 'fields' => ['title', 'abstract', 'keywords']];
    }


    private function workflowScientificResult(object $context, ReviewAssignment $review, IlluminateRequest $input): JsonResponse
    {
        $responses = $input->input('reviewFormResponses', []);
        if (!is_array($responses)) return $this->error('invalid_review_form_responses', 'Review responses must be an array.', 422);
        $validated = $this->validateReviewFormResponses($review, $responses);
        if ($validated instanceof JsonResponse) return $validated;
        $recommendation = $input->input('reviewerRecommendationExternalId');
        if ($recommendation !== null && $recommendation !== '') {
            if (!is_scalar($recommendation)) return $this->error('invalid_reviewer_recommendation', 'A native recommendation identifier is required.', 422);
            $written = $this->persistReviewerRecommendation($context, $review, (string)$recommendation);
            if ($written instanceof JsonResponse) return $written;
        }
        foreach ($validated as $elementId => $value) $this->saveReviewFormResponse($review, $elementId, $value);
        foreach (['authorAndEditorComment' => true, 'editorOnlyComment' => false] as $field => $viewable) {
            $body = trim((string)$input->input($field, ''));
            if ($body !== '') $this->saveReviewComment($review, $body, $viewable);
        }
        return response()->json(['written' => true]);
    }

    private function workflowEditorAllowed(object $context, object $submission, int $editorId): bool
    {
        if ($editorId < 1 || !Repo::user()->get($editorId)) return false;
        $stages = Repo::user()->getAccessibleWorkflowStages($editorId, $context->getId(), $submission);
        return (bool)array_intersect($stages[(int)$submission->getData('stageId')] ?? [], [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT]);
    }

    /** Atomic, idempotent return of the shareable Word revision and exact structured snapshot. */

    public function assignmentResult(IlluminateRequest $input): JsonResponse
    {
        $context = Application::get()->getRequest()->getContext();
        if (!$context) return $this->error('context_required', 'A press context is required.', 400);
        if ($error = $this->authorizeServiceRequest($input, $context->getId())) return $error;
        $submissionId = (int)$input->input('submissionExternalId');
        $submission = Repo::submission()->get($submissionId, $context->getId());
        if (!$submission) return $this->error('submission_not_found', 'Submission not found.', 404);
        $externalId = (string)$input->input('reviewAssignmentExternalId');
        $type = (string)$input->input('assignmentType');
        $roundNumber = (int)$input->input('reviewRound');
        $sourceDigest = (string)$input->input('sourceDigest');
        if (!preg_match('/^[a-f0-9]{64}$/', $sourceDigest) || $roundNumber < 1) return $this->error('invalid_result', 'A content digest and review round are required.', 422);
        $binding = null;
        $token = $input->input('assignmentToken');
        if ($token !== null) {
            $parts = is_string($token) ? explode('.', $token) : [];
            if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', 'workflow-assignment/1.' . $parts[0], (string)$this->plugin->getSetting($context->getId(), 'sharedSecret')), $parts[1])) return $this->error('invalid_assignment_token', 'The task binding is invalid.', 403);
            $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);
            $binding = $decoded === false ? null : json_decode($decoded, true);
            if (!is_array($binding) || ($binding['contextId'] ?? null) !== (int)$context->getId() || ($binding['submissionId'] ?? null) !== $submissionId || ($binding['assignmentId'] ?? null) !== $externalId || ($binding['assignmentType'] ?? null) !== $type || ($binding['reviewRound'] ?? null) !== $roundNumber) return $this->error('assignment_mismatch', 'The task belongs to another press, submission, role or round.', 403);
            if (!$this->workflowEditorAllowed($context, $submission, (int)$binding['editorId']) || !Repo::user()->get((int)$binding['participantId'])) return $this->error('assignment_revoked', 'The editorial task is no longer authorized.', 403);
        }
        $review = null;
        if ($type === 'SCIENTIFIC_REVIEW') {
            $review = ctype_digit($externalId) ? Repo::reviewAssignment()->get((int)$externalId, $submissionId) : null;
            if (!$review || (int)$review->getRound() !== $roundNumber || (int)$review->getReviewMethod() !== ReviewAssignment::SUBMISSION_REVIEW_METHOD_DOUBLEANONYMOUS || ($binding && (int)$review->getReviewerId() !== (int)$binding['participantId'])) return $this->error('review_assignment_mismatch', 'A double-anonymous review assignment in the requested round is required.', 403);
            $nativeRound = DAORegistry::getDAO('ReviewRoundDAO')->getById((int)$review->getReviewRoundId());
            if (!$nativeRound || (int)$nativeRound->getSubmissionId() !== $submissionId || !in_array((int)$nativeRound->getStageId(), [WORKFLOW_STAGE_ID_INTERNAL_REVIEW, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW], true)) return $this->error('review_round_mismatch', 'The review round does not belong to this submission.', 409);
        } elseif (!$binding || !in_array($type, ['LANGUAGE_REVIEW', 'TRANSLATION'], true)) return $this->error('assignment_required', 'A bound language review or translation task is required.', 403);
        $chapterId = (int)($binding['chapterId'] ?? $input->input('componentExternalId', 0));
        if ($chapterId > 0 && !DAORegistry::getDAO('ChapterDAO')->getChapter($chapterId, (int)$submission->getCurrentPublication()?->getId())) return $this->error('chapter_mismatch', 'The chapter does not belong to the current publication.', 403);
        if ($review) {
            $latest = DAORegistry::getDAO('ReviewRoundDAO')->getLastReviewRoundBySubmissionId($submissionId, (int)$review->getStageId());
            if ((int)$submission->getData('stageId') !== (int)$review->getStageId() || !$latest || (int)$latest->getId() !== (int)$review->getReviewRoundId()) return $this->error('review_round_changed', 'The current review stage or round changed.', 409);
            if ($chapterId > 0 && !$this->reviewComponentMatchesClaims(['component' => ['externalId' => (string)$chapterId]], $submission, $review)) return $this->error('review_component_forbidden', 'The review is not authorized for this chapter.', 403);
        }
        $files = $input->input('files');
        $translation = $type === 'TRANSLATION' ? $input->input('translationMetadata') : null;
        $metadataOnly = false;
        if ($type === 'TRANSLATION') {
            try {
                if (!is_array($translation) || !is_string($translation['targetLanguage'] ?? null) || $this->translationLanguage($translation['targetLanguage']) !== ($binding['targetLanguage'] ?? null) || !is_string($translation['sourceLanguage'] ?? null) || $this->translationLanguage($translation['sourceLanguage']) !== ($binding['sourceLanguage'] ?? null)) return $this->error('translation_pair_mismatch', 'The translated metadata belongs to another language pair.', 422);
            } catch (\InvalidArgumentException) { return $this->error('translation_pair_mismatch', 'Valid bound translation languages are required.', 422); }
            foreach (['title', 'abstract'] as $field) if (!is_string($translation[$field] ?? null) || trim($translation[$field]) === '') return $this->error('translation_metadata_required', 'Translated title, abstract and keywords are all required.', 422);
            if (!is_array($translation['keywords'] ?? null) || !array_is_list($translation['keywords']) || !$translation['keywords'] || count($translation['keywords']) > 100) return $this->error('translation_metadata_required', 'Translated keywords are required.', 422);
            foreach ($translation['keywords'] as $term) if (!is_string($term) || trim($term) === '' || mb_strlen($term) > 200) return $this->error('translation_metadata_required', 'Translated keywords must be nonempty terms.', 422);
            $metadataOnly = !in_array($binding['targetLanguage'], $this->workflowPublicationLanguages($context, $submission), true);
            if (!$metadataOnly && $input->input('documentTranslationCompleted') !== true) return $this->error('document_translation_required', 'Complete the full manuscript translation for this publication language.', 422);
        }
        if (!is_array($files) || count($files) !== ($metadataOnly ? 0 : 2)) return $this->error('documents_required', 'Return the Word document and structured snapshot together.', 422);
        $documents = [];
        $total = 0;
        foreach ($files as $file) {
            if (!is_array($file)) return $this->error('invalid_document', 'Invalid returned document.', 422);
            try { $document = WorkflowFileDocument::decode((string)($file['fileName'] ?? ''), (string)($file['mediaType'] ?? ''), (string)($file['contentBase64'] ?? '')); }
            catch (\InvalidArgumentException $e) { return $this->error('invalid_document', $e->getMessage(), 422); }
            $total += $document['byteLength'];
            $documents[] = $document;
        }
        if (!$metadataOnly && ($total > WorkflowFileDocument::MAX_BYTES || !str_ends_with($documents[0]['fileName'], '.docx') || !str_ends_with($documents[1]['fileName'], '.omi-review.json'))) return $this->error('invalid_documents', 'The document pair exceeds the limit or has incorrect formats.', 422);
        if (!$metadataOnly) {
        if ($type === 'TRANSLATION') foreach ($documents as $document) if (!str_starts_with($document['fileName'], 'translation-' . $binding['sourceLanguage'] . '-' . $binding['targetLanguage'] . '-assignment-')) return $this->error('translation_file_language', 'Translation filenames must identify the bound language pair.', 422);
        $structured = json_decode($documents[1]['bytes'], true);
        if (!is_array($structured) || ($structured['protocol'] ?? '') !== 'omi-review-revision/1' || ($structured['assignmentType'] ?? '') !== $type || ($structured['anonymityMode'] ?? '') !== ($review ? 'double_blind' : 'open')) return $this->error('invalid_snapshot', 'The structured document does not match this task.', 422);
        $manuscript = $structured['manuscript'] ?? [];
        if ($type === 'TRANSLATION' && (($manuscript['translation'] ?? null) !== $translation || empty($manuscript['blocks']))) return $this->error('translation_snapshot_mismatch', 'The translated manuscript must match the translated metadata.', 422);
        if ($review && (($manuscript['authorIdentity'] ?? '') !== 'hidden' || !empty($manuscript['authors']))) return $this->error('identity_disclosure', 'Scientific review documents must not contain author metadata.', 422);
        foreach ($manuscript['annotations'] ?? [] as $annotation) {
            if (($annotation['visibility'] ?? '') !== 'author_and_editor' || ($review && !empty($annotation['authorName']))) return $this->error('private_annotation', 'Shareable files must not contain private annotations or reviewer identity.', 422);
        }
        }
        $createdFiles = [];
        try {
        return DB::transaction(function () use (&$createdFiles, $input, $context, $submission, $submissionId, $externalId, $type, $binding, $review, $roundNumber, $sourceDigest, $documents, $translation, $metadataOnly, $chapterId) {
            try {
                DB::table('submissions')->where('submission_id', $submissionId)->lockForUpdate()->first();
                $receiptKey = 'workflowReceipt_' . hash('sha256', $externalId . ':' . $roundNumber . ($review ? '' : ':' . $sourceDigest));
                $previous = $this->plugin->getSetting($context->getId(), $receiptKey);
                if (is_array($previous)) {
                    if (($previous['sourceDigest'] ?? '') !== $sourceDigest || ($previous['submissionExternalId'] ?? '') !== (string)$submissionId) return $this->error('result_conflict', 'This task was already returned with different content.', 409);
                    return response()->json(array_merge($previous, ['unchanged' => true]));
                }
                $current = Repo::submission()->get($submissionId, $context->getId());
                if ($binding && !$this->workflowEditorAllowed($context, $current, (int)$binding['editorId'])) return $this->error('assignment_revoked', 'Editorial permissions changed.', 403);
                if ($binding && (int)$current->getData('stageId') !== (int)$binding['stageId']) return $this->error('workflow_changed', 'The OMP workflow stage changed. Reassign the task before returning it.', 409);
                if ($binding && in_array((int)$binding['stageId'], [WORKFLOW_STAGE_ID_INTERNAL_REVIEW, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW], true)) {
                    $latest = DAORegistry::getDAO('ReviewRoundDAO')->getLastReviewRoundBySubmissionId($submissionId, (int)$binding['stageId']);
                    if (!$latest || (int)$latest->getId() !== (int)$binding['reviewRoundId']) return $this->error('review_round_changed', 'The current review round changed. Reassign the task.', 409);
                }
                $metadataReceipt = null;
                if ($type === 'TRANSLATION') {
                    $nowRequired = in_array($binding['targetLanguage'], $this->workflowPublicationLanguages($context, $current), true);
                    if ($nowRequired === $metadataOnly) return $this->error('translation_scope_changed', 'The publication languages changed. Reconnect the task.', 409);
                }
                if ($review) {
                    $latest = DAORegistry::getDAO('ReviewRoundDAO')->getLastReviewRoundBySubmissionId($submissionId, (int)$review->getStageId());
                    if ((int)$current->getData('stageId') !== (int)$review->getStageId() || !$latest || (int)$latest->getId() !== (int)$review->getReviewRoundId()) return $this->error('review_round_changed', 'The review stage or round changed during transfer.', 409);
                }
                if ($chapterId > 0 && !DAORegistry::getDAO('ChapterDAO')->getChapter($chapterId, (int)$current->getCurrentPublication()?->getId())) return $this->error('chapter_changed', 'The chapter publication changed during transfer.', 409);
                $genreId = null;
                if ($review) {
                    $currentReview = Repo::reviewAssignment()->get((int)$externalId, $submissionId);
                    if (!$this->reviewAssignmentAllowsFileWrite($currentReview)) return $this->error('review_closed', 'The review assignment is no longer writable.', 409);
                    $fileStage = SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT;
                    $assocType = Application::ASSOC_TYPE_REVIEW_ASSIGNMENT;
                    $assocId = (int)$externalId;
                    $uploaderId = (int)$review->getReviewerId();
                } else {
                    $stage = (int)$binding['stageId'];
                    $fileStage = match ($stage) {
                        WORKFLOW_STAGE_ID_SUBMISSION => SubmissionFile::SUBMISSION_FILE_SUBMISSION,
                        WORKFLOW_STAGE_ID_INTERNAL_REVIEW => SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_REVISION,
                        WORKFLOW_STAGE_ID_EXTERNAL_REVIEW => SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION,
                        WORKFLOW_STAGE_ID_EDITING => SubmissionFile::SUBMISSION_FILE_COPYEDIT,
                        WORKFLOW_STAGE_ID_PRODUCTION => SubmissionFile::SUBMISSION_FILE_PRODUCTION_READY,
                        default => 0,
                    };
                    if (!$fileStage) return $this->error('unsupported_stage', 'This workflow stage does not accept returned documents.', 409);
                    $assocType = in_array($stage, [WORKFLOW_STAGE_ID_INTERNAL_REVIEW, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW], true) ? Application::ASSOC_TYPE_REVIEW_ROUND : Application::ASSOC_TYPE_SUBMISSION;
                    $assocId = in_array($stage, [WORKFLOW_STAGE_ID_INTERNAL_REVIEW, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW], true) ? (int)$binding['reviewRoundId'] : $submissionId;
                    $uploaderId = (int)$binding['participantId'];
                    $genres = $metadataOnly ? null : DAORegistry::getDAO('GenreDAO')->getEnabledByContextId($context->getId());
                    while ($genres && ($genre = $genres->next())) if (!$genre->getDependent()) {
                        if ($genreId === null) $genreId = (int)$genre->getId();
                        if ($genre->getKey() === 'SUBMISSION') { $genreId = (int)$genre->getId(); break; }
                    }
                    if (!$metadataOnly && !$genreId) return $this->error('genre_required', 'No enabled manuscript genre is available.', 422);
                }
                if ($type === 'TRANSLATION') $metadataReceipt = $this->writeWorkflowTranslationMetadata($context, $current, $binding, $translation);
                $receipts = [];
                foreach ($documents as $document) {
                    $stored = $this->persistWorkflowFile($context, $submission, $uploaderId, $fileStage, $assocType, $assocId, $genreId, $document, [], $chapterId ?: null);
                    if ($stored->getStatusCode() >= 400) throw new HttpResponseException($stored);
                    $fileReceipt = $stored->getData(true);
                    $storedFile = Repo::submissionFile()->get((int)$fileReceipt['submissionFileExternalId']);
                    $createdFiles[] = $storedFile->getData('fileId');
                    $receipts[] = $fileReceipt;
                }
                if ($review) {
                    $result = $this->workflowScientificResult($context, $review, $input);
                    if ($result->getStatusCode() >= 400) throw new HttpResponseException($result);
                    Repo::reviewAssignment()->edit(Repo::reviewAssignment()->get((int)$externalId, $submissionId), ['dateCompleted' => Core::getCurrentDate()]);
                } else {
                    $this->saveWorkflowComment($context, $submission, $uploaderId, (int)$binding['editorId'], $externalId, (string)$input->input('authorAndEditorComment', ''), true);
                    $this->saveWorkflowComment($context, $submission, $uploaderId, (int)$binding['editorId'], $externalId, (string)$input->input('editorOnlyComment', ''), false);
                }
                $receipt = ['protocol' => 'omi-assignment-result/1', 'written' => true, 'sourceDigest' => $sourceDigest,
                    'submissionExternalId' => (string)$submissionId, 'reviewAssignmentExternalId' => $externalId,
                    'assignmentType' => $type, 'anonymityMode' => $review ? 'double_blind' : 'open', 'files' => $receipts, 'translationMetadata' => $metadataReceipt,
                    'workContext' => $review ? null : $this->workAccountingContext($context, $current, Repo::publication()->get((int)$binding['publicationId'], $submissionId), (int)($binding['chapterId'] ?? 0))];
                $this->plugin->updateSetting($context->getId(), $receiptKey, $receipt, 'object');
                return response()->json($receipt);
            } catch (\Throwable $error) {
                // Delete bytes while their native file rows still exist in this transaction.
                foreach ($createdFiles as $fileId) app()->get('file')->delete($fileId);
                throw $error;
            }
        });
        } catch (HttpResponseException $error) {
            // Return the original validation response only after rollback.
            return $error->getResponse();
        }
    }


    private function saveWorkflowComment(object $context, object $submission, int $userId, int $editorId, string $assignmentId, string $body, bool $viewable): void
    {
        if (trim($body) === '') return;
        $participants = [$editorId, $userId];
        if ($viewable) {
            $authors = StageAssignment::withSubmissionIds([$submission->getId()])->withRoleIds([Role::ROLE_ID_AUTHOR])
                ->withStageIds([(int)$submission->getData('stageId')])->get()->pluck('user_id')->all();
            $participants = array_merge($participants, $authors);
        }
        // Native OMP 3.5 discussions are visible in the actual workflow UI.
        // Separate participant sets enforce confidentiality; no mail is sent here.
        Repo::query()->addQuery((int)$submission->getId(), (int)$submission->getData('stageId'),
            'Open Manuscript task ' . $assignmentId . ($viewable ? '' : ' — editor only'),
            nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
            Repo::user()->get($userId), array_values(array_unique($participants)), (int)$context->getId(), false);
    }


    private function workflowReviewForm(ReviewAssignment $assignment): ?array
    {
        $formId = (int)$assignment->getData('reviewFormId');
        if ($formId < 1) return null;
        /** @var \PKP\reviewForm\ReviewFormElementDAO $elementDao */
        $elementDao = DAORegistry::getDAO('ReviewFormElementDAO');
        /** @var \PKP\reviewForm\ReviewFormResponseDAO $responseDao */
        $responseDao = DAORegistry::getDAO('ReviewFormResponseDAO');
        $responseValues = $responseDao->getReviewReviewFormResponseValues($assignment->getId());
        $elements = [];
        $result = $elementDao->getByReviewFormId($formId);
        while ($element = $result->next()) {
            $possible = $element->getLocalizedPossibleResponses();
            $options = $this->reviewFormOptions($possible);
            $localizations = $this->reviewFormLocalizations($element);
            $elementId = (int)$element->getId();
            $elements[] = [
                'externalId' => (string)$elementId,
                'type' => $this->reviewFormElementType((int)$element->getElementType()),
                'question' => $this->reviewFormPlainText($element->getLocalizedQuestion()),
                'description' => $this->reviewFormPlainText($element->getLocalizedDescription()),
                'required' => (bool)$element->getRequired(),
                'authorVisible' => (bool)$element->getIncluded(),
                'options' => $options,
                'localizations' => $localizations,
                'value' => array_key_exists($elementId, $responseValues) ? $responseValues[$elementId] : null,
            ];
        }

        return ['externalId' => (string)$formId, 'elements' => $elements];
    }


    private function reviewAssignmentAllowsFileWrite(ReviewAssignment $assignment): bool
    {
        return !$assignment->getDateCompleted() && !in_array(
            $assignment->getStatus(),
            [
                ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_DECLINED,
                ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_COMPLETE,
                ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_THANKED,
                ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_CANCELLED,
            ],
            true
        );
    }


    private function persistWorkflowFile(
        object $context,
        object $submission,
        int $uploaderUserId,
        int $fileStage,
        int $assocType,
        int $assocId,
        ?int $genreId,
        array $document,
        array $receipt,
        ?int $chapterId = null
    ): JsonResponse {
        $temporary = tmpfile();
        if (!$temporary) {
            return $this->error('storage_error', 'Unable to allocate temporary workflow-file storage.', 500);
        }

        $fileId = null;
        $used = false;
        try {
            if (fwrite($temporary, $document['bytes']) !== $document['byteLength']) {
                throw new \RuntimeException('Unable to write the workflow file.');
            }

            $suffix = $document['extension'] !== '' ? '.' . $document['extension'] : '';
            $dir = Repo::submissionFile()->getSubmissionDir(
                (int)$context->getId(),
                (int)$submission->getId()
            );
            $fileId = app()->get('file')->add(
                stream_get_meta_data($temporary)['uri'],
                $dir . '/' . bin2hex(random_bytes(16)) . $suffix
            );

            $params = [
                'fileId' => $fileId,
                'submissionId' => (int)$submission->getId(),
                'uploaderUserId' => $uploaderUserId,
                'fileStage' => $fileStage,
                ...($chapterId !== null ? ['chapterId' => $chapterId] : []),
                // PKP's optional genreId is an integer, not nullable. Review
                // attachments have no genre. Ordinary submission-stage files
                // already belong to the submission through submissionId.
                ...($genreId !== null ? ['genreId' => $genreId] : []),
                ...($assocType !== Application::ASSOC_TYPE_SUBMISSION ? ['assocType' => $assocType, 'assocId' => $assocId] : []),
                'name' => [
                    $submission->getData('locale') => $document['fileName'],
                ],
            ];

            $errors = Repo::submissionFile()->validate(
                null,
                $params,
                $context->getSupportedSubmissionMetadataLocales(),
                $submission->getData('locale')
            );
            if ($errors) {
                return $this->error(
                    'workflow_file_validation_failed',
                    'The file failed native OMP submission-file validation.',
                    422,
                    ['fields' => $errors]
                );
            }

            $submissionFileId = Repo::submissionFile()->add(
                Repo::submissionFile()->newDataObject($params)
            );
            $used = true;
            $stored = app()->get('file')->get($fileId);

            return response()->json(array_merge($receipt, [
                'protocol' => WorkflowFileDocument::PROTOCOL,
                'submissionExternalId' => (string)$submission->getId(),
                'submissionFileExternalId' => (string)$submissionFileId,
                'fileStage' => $fileStage,
                'fileName' => $document['fileName'],
                'mediaType' => (string)($stored->mimetype ?? 'application/octet-stream'),
                'declaredMediaType' => $document['mediaType'],
                'byteLength' => $document['byteLength'],
                'sha256' => $document['sha256'],
                'written' => true,
            ]));
        } finally {
            fclose($temporary);
            if ($fileId !== null && !$used) {
                app()->get('file')->delete($fileId);
            }
        }
    }


    private function nativeReviewerRecommendationsAvailable(): bool
    {
        $application = Application::get();
        if (!method_exists($application, 'hasCustomizableReviewerRecommendation') || !$application->hasCustomizableReviewerRecommendation()) return false;
        if (
            !method_exists(Repo::class, 'reviewerRecommendation')
            || !method_exists(ReviewAssignment::class, 'getReviewerRecommendationId')
            || !method_exists(ReviewAssignment::class, 'setReviewerRecommendationId')
        ) {
            return false;
        }

        try {
            $repository = Repo::reviewerRecommendation();
            return is_object($repository) && method_exists($repository, 'getRecommendationOptions');
        } catch (\Throwable) {
            return false;
        }
    }


    private function reviewerRecommendationCatalog(object $context, ReviewAssignment $assignment): array
    {
        $storage = $this->nativeReviewerRecommendationsAvailable() ? 'native' : 'unavailable';
        $options = [];

        if ($storage === 'native') {
            $rawOptions = Repo::reviewerRecommendation()->getRecommendationOptions(
                context: $context,
                reviewAssignment: $assignment
            );
            foreach (is_array($rawOptions) ? $rawOptions : [] as $externalId => $label) {
                $externalId = (string)$externalId;
                if (!ctype_digit($externalId) || (int)$externalId < 1) continue;
                $options[] = [
                    'externalId' => $externalId,
                    'label' => $this->reviewFormPlainText($label),
                ];
            }
            $selected = method_exists($assignment, 'getReviewerRecommendationId')
                ? $assignment->getReviewerRecommendationId()
                : null;
        } else {
            $selected = null;
        }

        $selectedExternalId = is_scalar($selected) && ctype_digit((string)$selected)
            && in_array((string)$selected, array_column($options, 'externalId'), true)
            ? (string)$selected
            : null;

        return [
            'storage' => $storage,
            'options' => $options,
            'selectedExternalId' => $selectedExternalId,
        ];
    }


    private function persistReviewerRecommendation(object $context, ReviewAssignment $assignment, string $externalId): array|JsonResponse
    {
        $catalog = $this->reviewerRecommendationCatalog($context, $assignment);
        $selected = null;
        foreach ($catalog['options'] as $option) {
            if ($option['externalId'] === $externalId) {
                $selected = $option;
                break;
            }
        }
        if (!$selected) {
            return $this->error(
                'invalid_reviewer_recommendation',
                'The selected reviewer recommendation is not offered for this review assignment.',
                422,
                ['availableExternalIds' => array_column($catalog['options'], 'externalId')]
            );
        }

        if ($catalog['storage'] === 'native') {
            Repo::reviewAssignment()->edit($assignment, ['reviewerRecommendationId' => (int)$externalId]);
        } else {
            Repo::reviewAssignment()->edit($assignment, ['recommendation' => (int)$externalId]);
        }

        return [
            'externalId' => $externalId,
            'label' => $selected['label'],
            'storage' => $catalog['storage'],
        ];
    }


    public function reviewResult(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $context = Application::get()->getRequest()->getContext();
        if (!$context) return $this->error('context_required', 'A press context is required.', 400);
        $serviceError = $this->authorizeServiceRequest($illuminateRequest, $context->getId());
        if ($serviceError) return $serviceError;

        $submissionId = (int)$illuminateRequest->input('submissionExternalId', 0);
        $reviewAssignmentId = (int)$illuminateRequest->input('reviewAssignmentExternalId', 0);
        if ($submissionId < 1 || $reviewAssignmentId < 1) {
            return $this->error('invalid_review_result', 'A valid monograph submission and review assignment are required.', 400);
        }

        $reviewAssignment = Repo::reviewAssignment()->get($reviewAssignmentId, $submissionId);
        if (!($reviewAssignment instanceof ReviewAssignment) || $reviewAssignment->getCancelled() || $reviewAssignment->getDeclined()) {
            return $this->error('review_assignment_not_found', 'Review assignment not found or no longer writable.', 404);
        }
        if ($reviewAssignment->getDateCompleted()) {
            return $this->error('review_already_completed', 'A completed OMP review assignment is read-only.', 409);
        }
        $submission = Repo::submission()->get($submissionId, $context->getId());
        if (!$submission) return $this->error('submission_not_found', 'Monograph submission not found in this press.', 404);

        $authorComment = trim((string)$illuminateRequest->input('authorAndEditorComment', ''));
        $editorComment = trim((string)$illuminateRequest->input('editorOnlyComment', ''));
        $recommendation = trim((string)$illuminateRequest->input('recommendation', ''));
        if ($recommendation !== '') {
            return $this->error(
                'legacy_recommendation_not_supported',
                'Textual recommendations are not native OMP recommendation identifiers. Use review-result-v2 capability discovery instead.',
                422
            );
        }
        $formResponses = $illuminateRequest->input('reviewFormResponses', []);
        if (!is_array($formResponses)) return $this->error('invalid_review_form_responses', 'Review form responses must be an array.', 400);

        $validatedFormResponses = $this->validateReviewFormResponses($reviewAssignment, $formResponses);
        if ($validatedFormResponses instanceof JsonResponse) return $validatedFormResponses;
        if ($authorComment === '' && $editorComment === '' && $validatedFormResponses === []) {
            return $this->error('empty_review_result', 'The review result does not contain any writable content.', 400);
        }

        foreach ($validatedFormResponses as $elementId => $value) {
            $this->saveReviewFormResponse($reviewAssignment, $elementId, $value);
        }
        if ($authorComment !== '') $this->saveReviewComment($reviewAssignment, $authorComment, true);
        if ($editorComment !== '') $this->saveReviewComment($reviewAssignment, $editorComment, false);

        return response()->json([
            'protocol' => 'omi-integration/1',
            'profile' => Omp35Adapter::PROFILE,
            'submissionExternalId' => (string)$submissionId,
            'reviewAssignmentExternalId' => (string)$reviewAssignmentId,
            'reviewFormResponsesWritten' => count($validatedFormResponses),
            'written' => true,
            'deprecatedEndpoint' => true,
        ]);
    }

    private function validateReviewFormResponses(ReviewAssignment $assignment, array $responses): array|JsonResponse
    {
        $formId = (int)$assignment->getData('reviewFormId');
        if ($responses !== [] && $formId < 1) return $this->error('review_form_not_assigned', 'This review assignment does not use a review form.', 400);
        if ($formId < 1) return [];

        /** @var \PKP\reviewForm\ReviewFormElementDAO $elementDao */
        $elementDao = DAORegistry::getDAO('ReviewFormElementDAO');
        /** @var \PKP\reviewForm\ReviewFormResponseDAO $responseDao */
        $responseDao = DAORegistry::getDAO('ReviewFormResponseDAO');
        $existing = $responseDao->getReviewReviewFormResponseValues($assignment->getId());
        $validated = [];

        foreach ($responses as $response) {
            if (!is_array($response)) return $this->error('invalid_review_form_response', 'Each review form response must be an object.', 400);
            $elementId = (int)($response['elementExternalId'] ?? 0);
            if ($elementId < 1 || array_key_exists($elementId, $validated)) {
                return $this->error('invalid_review_form_element', 'Review form element identifiers must be valid and unique.', 400);
            }
            $element = $elementDao->getById($elementId, $formId);
            if (!($element instanceof ReviewFormElement)) {
                return $this->error('review_form_element_forbidden', 'A response references an element outside the assigned review form.', 403);
            }
            $normalized = $this->normalizeReviewFormValue($element, $response['value'] ?? null);
            if ($normalized instanceof JsonResponse) return $normalized;
            $validated[$elementId] = $normalized;
        }

        $requiredIds = $elementDao->getRequiredReviewFormElementIds($formId);
        foreach ($requiredIds as $requiredId) {
            $value = array_key_exists((int)$requiredId, $validated)
                ? $validated[(int)$requiredId]
                : ($existing[(int)$requiredId] ?? null);
            if ($this->reviewFormValueEmpty($value)) {
                return $this->error('review_form_required', 'All required OMP review form fields must be completed before submission.', 400, ['elementExternalId' => (string)$requiredId]);
            }
        }
        return $validated;
    }

    private function normalizeReviewFormValue(ReviewFormElement $element, mixed $value): mixed
    {
        $type = (int)$element->getElementType();
        $possible = $element->getLocalizedPossibleResponses();
        $allowed = is_array($possible) ? array_map('strval', array_keys($possible)) : [];

        if ($type === ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_CHECKBOXES) {
            if (!is_array($value)) return $this->error('invalid_review_form_value', 'Checkbox responses must be arrays.', 400);
            $values = array_values(array_unique(array_map('strval', $value)));
            foreach ($values as $item) {
                if (!in_array($item, $allowed, true)) return $this->error('invalid_review_form_option', 'A checkbox response contains an invalid option.', 400);
            }
            return $values;
        }
        if (in_array($type, [ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_RADIO_BUTTONS, ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_DROP_DOWN_BOX], true)) {
            if (!is_scalar($value) && $value !== null) return $this->error('invalid_review_form_value', 'Choice responses must contain one option.', 400);
            $scalar = $value === null ? '' : (string)$value;
            if ($scalar !== '' && !in_array($scalar, $allowed, true)) return $this->error('invalid_review_form_option', 'The selected review form option is invalid.', 400);
            return $scalar;
        }
        if (!in_array($type, [ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_SMALL_TEXT_FIELD, ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_TEXT_FIELD, ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_TEXTAREA], true)) {
            return $this->error('unsupported_review_form_element', 'The assigned OMP review form contains an unsupported element type.', 400);
        }
        if (!is_scalar($value) && $value !== null) return $this->error('invalid_review_form_value', 'Text review form responses must be text.', 400);
        $text = $value === null ? '' : (string)$value;
        if (mb_strlen($text) > 100000) return $this->error('review_form_value_too_long', 'A review form response exceeds the supported length.', 400);
        return $text;
    }

    private function reviewFormValueEmpty(mixed $value): bool
    {
        if (is_array($value)) return $value === [];
        return trim((string)($value ?? '')) === '';
    }

    private function reviewFormElementType(int $type): string
    {
        return match ($type) {
            ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_SMALL_TEXT_FIELD => 'small_text',
            ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_TEXT_FIELD => 'text',
            ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_TEXTAREA => 'textarea',
            ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_CHECKBOXES => 'checkboxes',
            ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_RADIO_BUTTONS => 'radio',
            ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_DROP_DOWN_BOX => 'dropdown',
            default => 'unsupported',
        };
    }

    private function reviewFormLocalizations(ReviewFormElement $element): array
    {
        $questions = $element->getData('question');
        $descriptions = $element->getData('description');
        $possibleResponses = $element->getData('possibleResponses');
        $locales = array_values(array_unique(array_merge(
            is_array($questions) ? array_keys($questions) : [],
            is_array($descriptions) ? array_keys($descriptions) : [],
            is_array($possibleResponses) ? array_keys($possibleResponses) : []
        )));
        $localizations = [];
        foreach ($locales as $locale) {
            $localizations[(string)$locale] = [
                'question' => $this->reviewFormPlainText(is_array($questions) ? ($questions[$locale] ?? '') : ''),
                'description' => $this->reviewFormPlainText(is_array($descriptions) ? ($descriptions[$locale] ?? '') : ''),
                'options' => $this->reviewFormOptions(is_array($possibleResponses) ? ($possibleResponses[$locale] ?? []) : []),
            ];
        }
        return $localizations;
    }

    private function reviewFormOptions(mixed $possible): array
    {
        if (!is_array($possible)) return [];
        $options = [];
        foreach ($possible as $value => $label) {
            $options[] = [
                'value' => (string)$value,
                'label' => $this->reviewFormPlainText($label),
            ];
        }
        return $options;
    }

    private function reviewFormPlainText(mixed $value): string
    {
        if (!is_scalar($value)) return '';
        $text = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<\s*br\s*\/?\s*>/i', ' ', $text) ?? $text;
        $text = preg_replace('/<\s*\/p\s*>/i', ' ', $text) ?? $text;
        $text = strip_tags($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    /**
     * Resolve the native OMP/PKP file stages visible to a signed author.
     *
     * The launch actor may also hold editorial roles. Author mode deliberately
     * strips every non-author stage role before delegating to PKP's
     * Repository::getAssignedFileStages(), so a dual-role account cannot use an
     * author assertion to read editorial or review-only files.
     *
     * @return array<int>|null Null when the signed actor has no author workflow
     * assignment for this submission.
     */
    private function authorReadableFileStages(
        array $claims,
        object $submission,
        object $context
    ): ?array {
        if (($claims['actorMode'] ?? '') !== 'author') return null;

        $actorId = (int)($claims['actor']['externalId'] ?? 0);
        if ($actorId < 1) return null;

        $stageAssignments = Repo::user()->getAccessibleWorkflowStages(
            $actorId,
            (int)$context->getId(),
            $submission
        );

        $authorAssignments = [];
        foreach ($stageAssignments as $stageId => $roles) {
            if (!is_array($roles) || !in_array(Role::ROLE_ID_AUTHOR, $roles, true)) {
                continue;
            }
            $authorAssignments[(int)$stageId] = [Role::ROLE_ID_AUTHOR];
        }
        if ($authorAssignments === []) return null;

        return array_values(array_unique(Repo::submissionFile()->getAssignedFileStages(
            $authorAssignments,
            SubmissionFileAccessPolicy::SUBMISSION_FILE_ACCESS_READ
        )));
    }

    private function reviewAssignmentForClaims(array $claims, int $submissionId): ?ReviewAssignment
    {
        if (($claims['actorMode'] ?? '') !== 'review') return null;
        if (!$this->hasScope($claims, 'review.manuscript.read')) return null;
        $assignmentId = (int)($claims['reviewAssignment']['externalId'] ?? 0);
        $actorId = (int)($claims['actor']['externalId'] ?? 0);
        if ($assignmentId < 1 || $actorId < 1) return null;
        $assignment = Repo::reviewAssignment()->get($assignmentId, $submissionId);
        if (!($assignment instanceof ReviewAssignment)) return null;
        if ((int)$assignment->getSubmissionId() !== $submissionId || (int)$assignment->getReviewerId() !== $actorId) return null;
        if ($assignment->getCancelled() || $assignment->getDeclined() || $assignment->getDateCompleted()) return null;

        $submission = Repo::submission()->get($submissionId);
        if (!$submission || (int)$submission->getData('stageId') !== (int)$assignment->getStageId()) return null;
        /** @var \PKP\submission\reviewRound\ReviewRoundDAO $reviewRoundDao */
        $reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');
        $currentRound = $reviewRoundDao->getCurrentRoundBySubmissionId($submissionId, (int)$assignment->getStageId());
        if ((int)$assignment->getRound() !== (int)$currentRound) return null;
        if (!$this->reviewComponentMatchesClaims($claims, $submission, $assignment)) return null;
        return $assignment;
    }

    private function reviewComponentMatchesClaims(array $claims, object $submission, ReviewAssignment $assignment): bool
    {
        $componentId = (int)($claims['component']['externalId'] ?? 0);
        if ($componentId < 1) return false;

        $publication = $submission->getCurrentPublication();
        if (!$publication) return false;
        /** @var \APP\monograph\ChapterDAO $chapterDao */
        $chapterDao = DAORegistry::getDAO('ChapterDAO');
        if (!$chapterDao->getChapter($componentId, (int)$publication->getId())) return false;

        /** @var ReviewFilesDAO $reviewFilesDao */
        $reviewFilesDao = DAORegistry::getDAO('ReviewFilesDAO');
        $chapterIds = [];
        $files = Repo::submissionFile()->getCollector()
            ->filterBySubmissionIds([(int)$submission->getId()])
            ->getMany();
        foreach ($files as $file) {
            if (!$reviewFilesDao->check((int)$assignment->getId(), (int)$file->getId())) continue;
            $chapterId = (int)$file->getData('chapterId');
            if ($chapterId > 0) $chapterIds[$chapterId] = true;
        }
        return count($chapterIds) === 1 && isset($chapterIds[$componentId]);
    }

    private function reviewFileAllowed(
        ReviewAssignment $reviewAssignment,
        int $submissionFileId,
        int $componentId = 0
    ): bool
    {
        if ($submissionFileId < 1) return false;
        /** @var ReviewFilesDAO $reviewFilesDao */
        $reviewFilesDao = DAORegistry::getDAO('ReviewFilesDAO');
        if (!$reviewFilesDao->check($reviewAssignment->getId(), $submissionFileId)) return false;
        if ($componentId < 1) return true;

        $file = Repo::submissionFile()->get($submissionFileId, (int)$reviewAssignment->getSubmissionId());
        return $file && (int)$file->getData('chapterId') === $componentId;
    }

    private function saveReviewFormResponse(ReviewAssignment $assignment, int $elementId, mixed $value): void
    {
        /** @var \PKP\reviewForm\ReviewFormElementDAO $elementDao */
        $elementDao = DAORegistry::getDAO('ReviewFormElementDAO');
        /** @var \PKP\reviewForm\ReviewFormResponseDAO $responseDao */
        $responseDao = DAORegistry::getDAO('ReviewFormResponseDAO');
        $element = $elementDao->getById($elementId, (int)$assignment->getReviewFormId());
        if (!($element instanceof ReviewFormElement)) return;

        $response = $responseDao->getReviewFormResponse((int)$assignment->getId(), $elementId)
            ?? new ReviewFormResponse();
        $responseType = match ((int)$element->getElementType()) {
            ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_CHECKBOXES => 'object',
            ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_RADIO_BUTTONS,
            ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_DROP_DOWN_BOX => 'int',
            default => 'string',
        };
        $response->setResponseType($responseType);
        $response->setValue($value);
        if ($response->getReviewId() !== null && $response->getReviewFormElementId() !== null) {
            $responseDao->updateObject($response);
            return;
        }
        $response->setReviewId((int)$assignment->getId());
        $response->setReviewFormElementId($elementId);
        $responseDao->insertObject($response);
    }

    private function saveReviewComment(ReviewAssignment $assignment, string $text, bool $viewable): void
    {
        /** @var \PKP\submission\SubmissionCommentDAO $commentDao */
        $commentDao = DAORegistry::getDAO('SubmissionCommentDAO');
        $comments = $commentDao->getReviewerCommentsByReviewerId(
            (int)$assignment->getSubmissionId(),
            (int)$assignment->getReviewerId(),
            (int)$assignment->getId(),
            $viewable
        );
        $comment = $comments->next() ?? $commentDao->newDataObject();
        $comment->setCommentType(SubmissionComment::COMMENT_TYPE_PEER_REVIEW);
        $comment->setRoleId(Role::ROLE_ID_REVIEWER);
        $comment->setSubmissionId((int)$assignment->getSubmissionId());
        $comment->setAssocId((int)$assignment->getId());
        $comment->setAuthorId((int)$assignment->getReviewerId());
        $comment->setCommentTitle('');
        $comment->setComments($text);
        $comment->setViewable($viewable);
        if ($comment->getId() !== null) {
            $comment->setDateModified(Core::getCurrentDate());
            $commentDao->updateObject($comment);
            return;
        }
        $comment->setDatePosted(Core::getCurrentDate());
        $commentDao->insertObject($comment);
    }

    private function authorizeServiceRequest(IlluminateRequest $request, int $contextId): ?JsonResponse
    {
        $installation = trim((string)$request->header('X-OMI-Installation', ''));
        $timestamp = trim((string)$request->header('X-OMI-Timestamp', ''));
        $signature = trim((string)$request->header('X-OMI-Signature', ''));
        if ($installation === '' || !ctype_digit($timestamp) || $signature === '') {
            return $this->error('service_authentication_required', 'Signed OMI service authentication is required.', 401);
        }
        if (abs(time() - (int)$timestamp) > self::SERVICE_CLOCK_SKEW_SECONDS) {
            return $this->error('service_assertion_expired', 'The OMI service assertion is outside the allowed clock window.', 401);
        }

        $expectedInstallation = $this->plugin->getInstallationId($contextId, Application::get()->getRequest());
        if (!hash_equals($expectedInstallation, $installation)) return $this->error('invalid_installation', 'The OMI installation identifier does not match this press.', 401);
        $secret = (string)$this->plugin->getSetting($contextId, 'sharedSecret');
        if ($secret === '') return $this->error('integration_not_configured', 'The integration shared secret is not configured.', 503);

        $body = (string)$request->getContent();
        $canonical = $timestamp . "\n" . strtoupper($request->getMethod()) . "\n" . $request->getPathInfo() . "\n" . hash('sha256', $body);
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $canonical, $secret, true)), '+/', '-_'), '=');
        if (!hash_equals($expected, $signature)) return $this->error('invalid_service_signature', 'The OMI service signature is invalid.', 401);
        return null;
    }

    private function authorizeSubmissionRequest(IlluminateRequest $illuminateRequest): array|JsonResponse
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) return $this->error('context_required', 'A press context is required.', 400);
        $authorization = trim((string)$illuminateRequest->header('Authorization', ''));
        if (!preg_match('/^OMI\s+([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/', $authorization, $matches)) {
            return $this->error('authentication_required', 'A signed OMI launch assertion is required.', 401);
        }
        $secret = (string)$this->plugin->getSetting($context->getId(), 'sharedSecret');
        if ($secret === '') return $this->error('integration_not_configured', 'The integration shared secret is not configured.', 503);
        $claims = LaunchToken::verify($matches[1], $matches[2], $secret, $context->getId());
        if (!$claims || ($claims['profile'] ?? null) !== Omp35Adapter::PROFILE) {
            return $this->error('invalid_assertion', 'The signed OMI assertion is invalid or expired.', 401);
        }
        $submissionId = (int)($claims['submission']['externalId'] ?? 0);
        if ($submissionId < 1) return $this->error('submission_required', 'The assertion does not identify a monograph submission.', 400);
        return [$claims, $submissionId, $context];
    }

    private function hasScope(array $claims, string $scope): bool
    {
        $scopes = is_array($claims['scope'] ?? null) ? $claims['scope'] : [];
        return in_array($scope, $scopes, true);
    }

    private function hasAnyScope(array $claims, array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if ($this->hasScope($claims, $scope)) return true;
        }
        return false;
    }

    private function error(string $code, string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json(['error' => array_merge(['code' => $code, 'message' => $message], $extra)], $status);
    }
}
