<?php
namespace APP\plugins\generic\studioIntegration;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\studioIntegration\classes\Adapters\Omp35Adapter;
use APP\plugins\generic\studioIntegration\classes\Core\LaunchToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as IlluminateRequest;
use Illuminate\Support\Facades\Route;
use PKP\core\PKPApplication;
use PKP\core\PKPBaseController;
use PKP\db\DAORegistry;
use PKP\file\FileManager;
use PKP\submission\GenreDAO;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\submission\reviewRound\ReviewRound;
use PKP\submission\reviewRound\ReviewRoundDAO;
use PKP\submissionFile\SubmissionFile;

/**
 * OMP-specific API operations whose semantics differ from OJS.
 *
 * These routes are deliberately implemented against OMP/PKP 3.5 repository
 * services. In particular, OMP currently reports
 * Application::hasCustomizableReviewerRecommendation() === false, so this
 * controller never invents OJS recommendation IDs.
 */
class StudioIntegrationNativeApiController extends PKPBaseController
{
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
        Route::get('platform-capabilities', $this->platformCapabilities(...))
            ->name('api.omiIntegration.platformCapabilities');
        Route::get('review-context', $this->reviewContext(...))
            ->name('api.omiIntegration.reviewContext');
        Route::get('review-attachments', $this->reviewAttachments(...))
            ->name('api.omiIntegration.reviewAttachments');
        Route::post('review-attachments', $this->uploadReviewAttachment(...))
            ->name('api.omiIntegration.reviewAttachment.upload');
        Route::post('author-revisions', $this->uploadAuthorRevision(...))
            ->name('api.omiIntegration.authorRevision.upload');
        Route::post('review-result-v2', $this->reviewResultV2(...))
            ->name('api.omiIntegration.reviewResultV2');
    }

    public function platformCapabilities(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $context = Application::get()->getRequest()->getContext();
        if (!$context) {
            return $this->error('context_required', 'A press context is required.', 400);
        }

        return response()->json([
            'protocol' => 'omi-integration/1',
            'profile' => Omp35Adapter::PROFILE,
            'implementation' => [
                'name' => 'Open Manuscript Studio Integration for OMP',
                'version' => '1.2.3',
                'platform' => 'omp',
            ],
            'nativeApis' => [
                'reviewRecommendations' => [
                    'supported' => Application::get()->hasCustomizableReviewerRecommendation(),
                    'authority' => 'APP\\core\\Application::hasCustomizableReviewerRecommendation',
                ],
                'reviewAttachments' => [
                    'supported' => true,
                    'fileStage' => SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT,
                    'association' => 'reviewAssignment',
                ],
                'authorRevisions' => [
                    'supported' => true,
                    'externalFileStage' => SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION,
                    'internalFileStage' => SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_REVISION,
                    'association' => 'reviewRound',
                    'currentRoundOnly' => true,
                ],
            ],
        ]);
    }

    public function reviewContext(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $authorized = $this->authorizeSubmissionRequest($illuminateRequest);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$claims, $submissionId, $context] = $authorized;

        if (($claims['actorMode'] ?? '') !== 'review' || !$this->hasScope($claims, 'review.metadata.read')) {
            return $this->error('insufficient_scope', 'Reviewer context access requires review.metadata.read.', 403);
        }

        $assignment = $this->reviewAssignmentForClaims($claims, $submissionId);
        if (!$assignment) {
            return $this->error('review_assignment_forbidden', 'The review assignment is not valid for the current OMP review round.', 403);
        }

        $recommendationsSupported = Application::get()->hasCustomizableReviewerRecommendation();
        $recommendationOptions = $recommendationsSupported
            ? Repo::reviewerRecommendation()->getRecommendationOptions(
                context: $context,
                reviewAssignment: $assignment
            )
            : [];

        $options = [];
        foreach ($recommendationOptions as $id => $label) {
            $options[] = ['externalId' => (string)$id, 'label' => (string)$label];
        }

        return response()->json([
            'protocol' => 'omi-integration/1',
            'profile' => Omp35Adapter::PROFILE,
            'submissionExternalId' => (string)$submissionId,
            'reviewAssignment' => [
                'externalId' => (string)$assignment->getId(),
                'reviewRoundExternalId' => (string)$assignment->getReviewRoundId(),
                'round' => (int)$assignment->getRound(),
                'stageId' => (int)$assignment->getStageId(),
                'dateCompleted' => $assignment->getDateCompleted(),
                'cancelled' => (bool)$assignment->getCancelled(),
                'declined' => (bool)$assignment->getDeclined(),
            ],
            'reviewRecommendations' => [
                'supported' => $recommendationsSupported,
                'options' => $options,
                'selectedExternalId' => $assignment->getData('reviewerRecommendationId') !== null
                    ? (string)$assignment->getData('reviewerRecommendationId')
                    : null,
            ],
        ]);
    }

    public function reviewAttachments(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $authorized = $this->authorizeSubmissionRequest($illuminateRequest);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$claims, $submissionId] = $authorized;

        if (($claims['actorMode'] ?? '') !== 'review' || !$this->hasScope($claims, 'review.files.read')) {
            return $this->error('insufficient_scope', 'Reviewer attachment access requires review.files.read.', 403);
        }
        $assignment = $this->reviewAssignmentForClaims($claims, $submissionId);
        if (!$assignment) {
            return $this->error('review_assignment_forbidden', 'The review assignment is not valid for the current OMP review round.', 403);
        }

        $actorId = (int)($claims['actor']['externalId'] ?? 0);
        $files = Repo::submissionFile()->getCollector()
            ->filterBySubmissionIds([$submissionId])
            ->filterByReviewRoundIds([$assignment->getReviewRoundId()])
            ->filterByUploaderUserIds([$actorId])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT])
            ->filterByAssoc(PKPApplication::ASSOC_TYPE_REVIEW_ASSIGNMENT, [$assignment->getId()])
            ->getMany();

        $items = [];
        foreach ($files as $file) {
            $items[] = $this->mapSubmissionFile($file);
        }

        return response()->json([
            'protocol' => 'omi-integration/1',
            'profile' => Omp35Adapter::PROFILE,
            'submissionExternalId' => (string)$submissionId,
            'reviewAssignmentExternalId' => (string)$assignment->getId(),
            'items' => $items,
        ]);
    }

    public function uploadReviewAttachment(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $authorized = $this->authorizeSubmissionRequest($illuminateRequest);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$claims, $submissionId, $context] = $authorized;

        if (($claims['actorMode'] ?? '') !== 'review' || !$this->hasScope($claims, 'review.revision.write')) {
            return $this->error('insufficient_scope', 'Reviewer attachment upload requires review.revision.write.', 403);
        }
        $assignment = $this->reviewAssignmentForClaims($claims, $submissionId);
        if (!$assignment) {
            return $this->error('review_assignment_forbidden', 'The review assignment is not valid for the current OMP review round.', 403);
        }
        if ($assignment->getDateCompleted()) {
            return $this->error('review_already_completed', 'A completed review assignment can no longer receive reviewer attachments.', 409);
        }

        $submission = Repo::submission()->get($submissionId, $context->getId());
        if (!$submission) return $this->error('submission_not_found', 'Monograph submission not found.', 404);

        $genreId = $this->resolveGenreId($illuminateRequest, $submissionId, $context->getId());
        if ($genreId instanceof JsonResponse) return $genreId;

        return $this->storeUploadedSubmissionFile(
            $illuminateRequest,
            $submission,
            (int)($claims['actor']['externalId'] ?? 0),
            SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT,
            PKPApplication::ASSOC_TYPE_REVIEW_ASSIGNMENT,
            $assignment->getId(),
            $genreId,
            null
        );
    }

    public function uploadAuthorRevision(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $authorized = $this->authorizeSubmissionRequest($illuminateRequest);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$claims, $submissionId, $context] = $authorized;

        if (($claims['actorMode'] ?? '') !== 'author' || !$this->hasScope($claims, 'author.revision.write')) {
            return $this->error('insufficient_scope', 'Author revision upload requires author.revision.write.', 403);
        }

        $roundId = (int)$illuminateRequest->input('reviewRoundExternalId', 0);
        if ($roundId < 1) {
            return $this->error('review_round_required', 'A valid reviewRoundExternalId is required.', 400);
        }

        /** @var ReviewRoundDAO $reviewRoundDao */
        $reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');
        $reviewRound = $reviewRoundDao->getById($roundId);
        if (!($reviewRound instanceof ReviewRound) || (int)$reviewRound->getSubmissionId() !== $submissionId) {
            return $this->error('review_round_not_found', 'The review round does not belong to this monograph.', 404);
        }

        $stageId = (int)$reviewRound->getStageId();
        if (!in_array($stageId, Application::get()->getReviewStages(), true)) {
            return $this->error('invalid_review_round_stage', 'The requested round is not an OMP review-stage round.', 400);
        }

        $submission = Repo::submission()->get($submissionId, $context->getId());
        if (!$submission) return $this->error('submission_not_found', 'Monograph submission not found.', 404);
        if ((int)$submission->getData('stageId') !== $stageId) {
            return $this->error('review_round_not_current_stage', 'Author revisions may only be uploaded to the current OMP review stage.', 409);
        }
        $currentRound = $reviewRoundDao->getCurrentRoundBySubmissionId($submissionId, $stageId);
        if ((int)$reviewRound->getRound() !== (int)$currentRound) {
            return $this->error('review_round_not_current', 'Author revisions may only be uploaded to the current OMP review round.', 409);
        }

        $sourceId = (int)$illuminateRequest->input('sourceSubmissionFileExternalId', 0);
        $sourceFile = $sourceId > 0 ? Repo::submissionFile()->get($sourceId, $submissionId) : null;
        if ($sourceId > 0 && !$sourceFile) {
            return $this->error('source_file_not_found', 'The source submission file does not belong to this monograph.', 404);
        }

        $genreId = $this->resolveGenreId($illuminateRequest, $submissionId, $context->getId(), $sourceFile);
        if ($genreId instanceof JsonResponse) return $genreId;

        $fileStage = $stageId === WORKFLOW_STAGE_ID_INTERNAL_REVIEW
            ? SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_REVISION
            : SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION;

        return $this->storeUploadedSubmissionFile(
            $illuminateRequest,
            $submission,
            (int)($claims['actor']['externalId'] ?? 0),
            $fileStage,
            PKPApplication::ASSOC_TYPE_REVIEW_ROUND,
            $roundId,
            $genreId,
            $sourceFile
        );
    }

    public function reviewResultV2(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $authorized = $this->authorizeSubmissionRequest($illuminateRequest);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$claims, $submissionId, $context] = $authorized;

        if (($claims['actorMode'] ?? '') !== 'review' || !$this->hasScope($claims, 'review.response.write')) {
            return $this->error('insufficient_scope', 'Review response writeback requires review.response.write.', 403);
        }
        $assignment = $this->reviewAssignmentForClaims($claims, $submissionId);
        if (!$assignment) {
            return $this->error('review_assignment_forbidden', 'The review assignment is not valid for the current OMP review round.', 403);
        }
        if ($assignment->getDateCompleted()) {
            return $this->error('review_already_completed', 'The review assignment has already been completed in OMP.', 409);
        }

        $recommendation = $illuminateRequest->input('reviewerRecommendationExternalId');
        if ($recommendation !== null && $recommendation !== '') {
            if (!Application::get()->hasCustomizableReviewerRecommendation()) {
                return $this->error(
                    'review_recommendations_not_supported',
                    'This OMP installation does not support customizable reviewer recommendations.',
                    422
                );
            }
            if (!is_scalar($recommendation) || !ctype_digit((string)$recommendation)) {
                return $this->error('invalid_reviewer_recommendation', 'The reviewer recommendation identifier is invalid.', 400);
            }
            $options = Repo::reviewerRecommendation()->getRecommendationOptions(
                context: $context,
                reviewAssignment: $assignment
            );
            $recommendationId = (int)$recommendation;
            if (!array_key_exists($recommendationId, $options)) {
                return $this->error('invalid_reviewer_recommendation', 'The reviewer recommendation is not available for this OMP review assignment.', 400);
            }
            Repo::reviewAssignment()->edit($assignment, ['reviewerRecommendationId' => $recommendationId]);
        }

        $legacyRecommendation = trim((string)$illuminateRequest->input('recommendation', ''));
        if ($legacyRecommendation !== '') {
            return $this->error(
                'legacy_recommendation_not_supported',
                'Textual recommendation values are not valid OMP recommendation identifiers and are not written as comments.',
                422
            );
        }

        $authorComment = trim((string)$illuminateRequest->input('authorAndEditorComment', ''));
        $editorComment = trim((string)$illuminateRequest->input('editorOnlyComment', ''));
        if ($authorComment === '' && $editorComment === '' && ($recommendation === null || $recommendation === '')) {
            return $this->error('empty_review_result', 'The review result does not contain writable content.', 400);
        }

        if ($authorComment !== '') Repo::reviewAssignment()->saveReviewComment($assignment, $authorComment, true);
        if ($editorComment !== '') Repo::reviewAssignment()->saveReviewComment($assignment, $editorComment, false);

        return response()->json([
            'protocol' => 'omi-integration/1',
            'profile' => Omp35Adapter::PROFILE,
            'submissionExternalId' => (string)$submissionId,
            'reviewAssignmentExternalId' => (string)$assignment->getId(),
            'reviewRecommendationWritten' => $recommendation !== null && $recommendation !== '',
            'written' => true,
            'reviewCompleted' => false,
            'completionAuthority' => 'OMP reviewer workflow',
        ]);
    }

    private function storeUploadedSubmissionFile(
        IlluminateRequest $request,
        object $submission,
        int $uploaderUserId,
        int $fileStage,
        int $assocType,
        int $assocId,
        int $genreId,
        ?object $sourceFile
    ): JsonResponse {
        $upload = $request->file('file');
        if (!$upload || !$upload->isValid()) {
            return $this->error('file_required', 'A valid multipart file field named file is required.', 400);
        }
        if ($uploaderUserId < 1 || !Repo::user()->get($uploaderUserId)) {
            return $this->error('invalid_uploader', 'The signed assertion does not identify a valid OMP user.', 403);
        }

        $originalName = trim((string)$upload->getClientOriginalName());
        if ($originalName === '') $originalName = 'open-manuscript-revision';

        $fileManager = new FileManager();
        $extension = $fileManager->parseFileExtension($originalName);
        $submissionDir = Repo::submissionFile()->getSubmissionDir(
            (int)$submission->getData('contextId'),
            (int)$submission->getId()
        );
        $targetName = uniqid('', true) . ($extension !== '' ? '.' . $extension : '');
        $realPath = $upload->getRealPath();
        if ($realPath === false) {
            return $this->error('upload_unavailable', 'The uploaded temporary file is not available.', 400);
        }

        try {
            $fileId = app()->get('file')->add($realPath, $submissionDir . '/' . $targetName);
        } catch (\Throwable) {
            return $this->error('file_storage_failed', 'OMP could not store the uploaded file.', 500);
        }

        $locale = (string)$submission->getData('locale');
        $params = [
            'fileId' => $fileId,
            'fileStage' => $fileStage,
            'name' => [$locale => $originalName],
            'submissionId' => (int)$submission->getId(),
            'uploaderUserId' => $uploaderUserId,
            'genreId' => $genreId,
            'assocType' => $assocType,
            'assocId' => $assocId,
        ];
        if ($sourceFile) {
            $params['sourceSubmissionFileId'] = (int)$sourceFile->getId();
        }
        $summary = trim((string)$request->input('summaryOfChanges', ''));
        if ($summary !== '') $params['summaryOfChanges'] = $summary;

        $context = Application::get()->getRequest()->getContext();
        $allowedLocales = $context->getSupportedSubmissionMetadataLocales();
        $errors = Repo::submissionFile()->validate(null, $params, $allowedLocales, $locale);
        if (!empty($errors)) {
            app()->get('file')->delete($fileId);
            return response()->json(['error' => [
                'code' => 'invalid_submission_file',
                'message' => 'OMP rejected the submitted file metadata.',
                'validation' => $errors,
            ]], 400);
        }

        try {
            $submissionFile = Repo::submissionFile()->newDataObject($params);
            $submissionFileId = Repo::submissionFile()->add($submissionFile);
            $submissionFile = Repo::submissionFile()->get($submissionFileId, (int)$submission->getId());
        } catch (\Throwable) {
            app()->get('file')->delete($fileId);
            return $this->error('submission_file_write_failed', 'OMP could not create the native submission file record.', 500);
        }

        return response()->json([
            'protocol' => 'omi-integration/1',
            'profile' => Omp35Adapter::PROFILE,
            'submissionExternalId' => (string)$submission->getId(),
            'file' => $this->mapSubmissionFile($submissionFile),
            'written' => true,
        ]);
    }

    private function resolveGenreId(
        IlluminateRequest $request,
        int $submissionId,
        int $contextId,
        ?object $sourceFile = null
    ): int|JsonResponse {
        $requestedGenre = (int)$request->input('genreExternalId', 0);
        /** @var GenreDAO $genreDao */
        $genreDao = DAORegistry::getDAO('GenreDAO');

        if ($requestedGenre > 0) {
            $genre = $genreDao->getById($requestedGenre, $contextId);
            return $genre ? $requestedGenre : $this->error('genre_not_found', 'The requested file genre is not available in this press.', 400);
        }

        if ($sourceFile && (int)$sourceFile->getData('genreId') > 0) {
            return (int)$sourceFile->getData('genreId');
        }

        $genres = $genreDao->getEnabledByContextId($contextId);
        $first = $genres->next();
        $second = $genres->next();
        if ($first && !$second) return (int)$first->getId();

        return $this->error(
            'genre_required',
            'This press has multiple enabled file genres. Supply genreExternalId or sourceSubmissionFileExternalId.',
            400
        );
    }

    private function mapSubmissionFile(?object $file): ?array
    {
        if (!$file) return null;
        return [
            'externalId' => (string)$file->getId(),
            'name' => $file->getData('name') ?: [],
            'mediaType' => (string)($file->getData('mimetype') ?? ''),
            'fileStage' => (int)$file->getData('fileStage'),
            'genreExternalId' => $file->getData('genreId') !== null ? (string)$file->getData('genreId') : null,
            'assocType' => $file->getData('assocType'),
            'assocExternalId' => $file->getData('assocId') !== null ? (string)$file->getData('assocId') : null,
            'sourceSubmissionFileExternalId' => $file->getData('sourceSubmissionFileId') !== null
                ? (string)$file->getData('sourceSubmissionFileId')
                : null,
            'createdAt' => $file->getData('createdAt'),
            'updatedAt' => $file->getData('updatedAt'),
        ];
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

        $submission = Repo::submission()->get($submissionId, $context->getId());
        if (!$submission) return $this->error('submission_not_found', 'Monograph submission not found in this press.', 404);

        return [$claims, $submissionId, $context];
    }

    private function reviewAssignmentForClaims(array $claims, int $submissionId): ?ReviewAssignment
    {
        if (($claims['actorMode'] ?? '') !== 'review') return null;
        $assignmentId = (int)($claims['reviewAssignment']['externalId'] ?? 0);
        $actorId = (int)($claims['actor']['externalId'] ?? 0);
        if ($assignmentId < 1 || $actorId < 1) return null;

        $assignment = Repo::reviewAssignment()->get($assignmentId, $submissionId);
        if (!($assignment instanceof ReviewAssignment)) return null;
        if ((int)$assignment->getSubmissionId() !== $submissionId || (int)$assignment->getReviewerId() !== $actorId) return null;
        if ($assignment->getCancelled() || $assignment->getDeclined()) return null;

        $submission = Repo::submission()->get($submissionId);
        if (!$submission || (int)$submission->getData('stageId') !== (int)$assignment->getStageId()) return null;
        /** @var ReviewRoundDAO $reviewRoundDao */
        $reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');
        $currentRound = $reviewRoundDao->getCurrentRoundBySubmissionId($submissionId, (int)$assignment->getStageId());
        if ((int)$assignment->getRound() !== (int)$currentRound) return null;

        return $assignment;
    }

    private function hasScope(array $claims, string $scope): bool
    {
        $scopes = is_array($claims['scope'] ?? null) ? $claims['scope'] : [];
        return in_array($scope, $scopes, true);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
