<?php
namespace APP\plugins\generic\studioIntegration;

use APP\core\Application;
use APP\decision\Decision;
use APP\facades\Repo;
use APP\plugins\generic\studioIntegration\classes\Adapters\Omp35Adapter;
use APP\plugins\generic\studioIntegration\classes\Core\LaunchToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as IlluminateRequest;
use Illuminate\Support\Facades\Route;
use PKP\core\Core;
use PKP\core\PKPApplication;
use PKP\core\PKPBaseController;
use PKP\db\DAORegistry;
use PKP\file\FileManager;
use PKP\reviewForm\ReviewFormElement;
use PKP\reviewForm\ReviewFormResponse;
use PKP\security\Role;
use PKP\submission\GenreDAO;
use PKP\submission\ReviewFilesDAO;
use PKP\submission\SubmissionComment;
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
    private const SERVICE_CLOCK_SKEW_SECONDS = 300;
    private const MAX_SERVICE_FILE_BYTES = 25 * 1024 * 1024;

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
        Route::get('author-context', $this->authorContext(...))
            ->name('api.omiIntegration.authorContext');
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
                'version' => '1.4.2',
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
                'serviceWriteback' => [
                    'supported' => true,
                    'authentication' => 'omi-hmac-sha256',
                    'reviewAttachments' => 'review-attachments',
                    'authorRevisions' => 'author-revisions',
                    'reviewResult' => 'review-result-v2',
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

    public function authorContext(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $authorized = $this->authorizeSubmissionRequest($illuminateRequest);
        if ($authorized instanceof JsonResponse) return $authorized;
        [$claims, $submissionId, $context] = $authorized;

        if (($claims['actorMode'] ?? '') !== 'author' || !$this->hasScope($claims, 'author.revision.write')) {
            return $this->error('insufficient_scope', 'Author context access requires author.revision.write.', 403);
        }

        $submission = Repo::submission()->get($submissionId, $context->getId());
        if (!$submission) {
            return $this->error('submission_not_found', 'Monograph submission not found.', 404);
        }

        $actorId = (int)($claims['actor']['externalId'] ?? 0);
        if (!$this->authorAssignedToCurrentStage($actorId, $submission, $context)) {
            return $this->error(
                'author_not_assigned',
                'The signed author is not assigned to the current OMP workflow stage.',
                403
            );
        }

        $stageId = (int)$submission->getData('stageId');
        if (!in_array($stageId, Application::get()->getReviewStages(), true)) {
            return response()->json([
                'protocol' => 'omi-integration/1',
                'profile' => Omp35Adapter::PROFILE,
                'submissionExternalId' => (string)$submissionId,
                'writable' => false,
                'reason' => 'not_in_review_stage',
                'reviewRoundExternalId' => null,
            ]);
        }

        /** @var ReviewRoundDAO $reviewRoundDao */
        $reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');
        $reviewRound = $reviewRoundDao->getLastReviewRoundBySubmissionId($submissionId, $stageId);
        if (!($reviewRound instanceof ReviewRound)) {
            return response()->json([
                'protocol' => 'omi-integration/1',
                'profile' => Omp35Adapter::PROFILE,
                'submissionExternalId' => (string)$submissionId,
                'writable' => false,
                'reason' => 'review_round_unavailable',
                'reviewRoundExternalId' => null,
            ]);
        }

        $writable = $this->reviewRoundAllowsAuthorRevision($reviewRound);
        return response()->json([
            'protocol' => 'omi-integration/1',
            'profile' => Omp35Adapter::PROFILE,
            'submissionExternalId' => (string)$submissionId,
            'writable' => $writable,
            'reason' => $writable ? null : 'revision_not_requested',
            'reviewRoundExternalId' => (string)$reviewRound->getId(),
            'round' => (int)$reviewRound->getRound(),
            'stageId' => $stageId,
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
        $serviceMode = trim((string)$illuminateRequest->header('X-OMI-Installation', '')) !== '';
        if ($serviceMode) {
            $context = Application::get()->getRequest()->getContext();
            if (!$context) return $this->error('context_required', 'A press context is required.', 400);
            $serviceError = $this->authorizeServiceRequest($illuminateRequest, (int)$context->getId());
            if ($serviceError) return $serviceError;

            $submissionId = (int)$illuminateRequest->input('submissionExternalId', 0);
            $reviewerId = (int)$illuminateRequest->input('actorExternalId', 0);
            $assignmentId = (int)$illuminateRequest->input('reviewAssignmentExternalId', 0);
            $reviewRoundId = (int)$illuminateRequest->input('reviewRoundExternalId', 0);
            if ($submissionId < 1 || $reviewerId < 1 || $assignmentId < 1 || $reviewRoundId < 1) {
                return $this->error(
                    'invalid_review_attachment_context',
                    'Service writeback requires submissionExternalId, actorExternalId, reviewAssignmentExternalId and reviewRoundExternalId.',
                    400
                );
            }
            $assignment = $this->reviewAssignmentForServiceWrite(
                $submissionId,
                $assignmentId,
                $reviewerId,
                $reviewRoundId
            );
            if (!$assignment) {
                return $this->error(
                    'review_assignment_forbidden',
                    'The requested review assignment is not writable in the current OMP review round.',
                    403
                );
            }
            $submission = Repo::submission()->get($submissionId, $context->getId());
            if (!$submission) return $this->error('submission_not_found', 'Monograph submission not found.', 404);
            $uploaderId = $reviewerId;
        } else {
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
            $submission = Repo::submission()->get($submissionId, $context->getId());
            if (!$submission) return $this->error('submission_not_found', 'Monograph submission not found.', 404);
            $uploaderId = (int)($claims['actor']['externalId'] ?? 0);
        }

        if ($assignment->getDateCompleted()) {
            return $this->error('review_already_completed', 'A completed review assignment can no longer receive reviewer attachments.', 409);
        }

        $genreId = $this->resolveGenreId($illuminateRequest, $submissionId, $context->getId());
        if ($genreId instanceof JsonResponse) return $genreId;

        return $this->storeUploadedSubmissionFile(
            $illuminateRequest,
            $submission,
            $uploaderId,
            SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT,
            PKPApplication::ASSOC_TYPE_REVIEW_ASSIGNMENT,
            $assignment->getId(),
            $genreId,
            null
        );
    }

    public function uploadAuthorRevision(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $serviceMode = trim((string)$illuminateRequest->header('X-OMI-Installation', '')) !== '';
        if ($serviceMode) {
            $context = Application::get()->getRequest()->getContext();
            if (!$context) return $this->error('context_required', 'A press context is required.', 400);
            $serviceError = $this->authorizeServiceRequest($illuminateRequest, (int)$context->getId());
            if ($serviceError) return $serviceError;

            $submissionId = (int)$illuminateRequest->input('submissionExternalId', 0);
            $authorId = (int)$illuminateRequest->input('actorExternalId', 0);
            if ($submissionId < 1 || $authorId < 1) {
                return $this->error(
                    'invalid_author_revision_context',
                    'Service writeback requires submissionExternalId and actorExternalId.',
                    400
                );
            }
            $submission = Repo::submission()->get($submissionId, $context->getId());
            if (!$submission) return $this->error('submission_not_found', 'Monograph submission not found.', 404);
        } else {
            $authorized = $this->authorizeSubmissionRequest($illuminateRequest);
            if ($authorized instanceof JsonResponse) return $authorized;
            [$claims, $submissionId, $context] = $authorized;
            if (($claims['actorMode'] ?? '') !== 'author' || !$this->hasScope($claims, 'author.revision.write')) {
                return $this->error('insufficient_scope', 'Author revision upload requires author.revision.write.', 403);
            }
            $submission = Repo::submission()->get($submissionId, $context->getId());
            if (!$submission) return $this->error('submission_not_found', 'Monograph submission not found.', 404);
            $authorId = (int)($claims['actor']['externalId'] ?? 0);
        }

        if (!$this->authorAssignedToCurrentStage($authorId, $submission, $context)) {
            return $this->error(
                'author_revision_forbidden',
                'The author is not assigned to the current OMP workflow stage.',
                403
            );
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
        if ((int)$submission->getData('stageId') !== $stageId) {
            return $this->error('review_round_not_current_stage', 'Author revisions may only be uploaded to the current OMP review stage.', 409);
        }

        $latestRound = $reviewRoundDao->getLastReviewRoundBySubmissionId($submissionId, $stageId);
        if (
            !($latestRound instanceof ReviewRound) ||
            (int)$latestRound->getId() !== $roundId ||
            (int)$latestRound->getRound() !== (int)$reviewRound->getRound()
        ) {
            return $this->error('review_round_not_current', 'Author revisions may only be uploaded to the current OMP review round.', 409);
        }
        if (!$this->reviewRoundAllowsAuthorRevision($reviewRound)) {
            return $this->error(
                'author_revision_not_requested',
                'OMP has not opened the current review round for author revision upload.',
                409
            );
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
            $authorId,
            $fileStage,
            PKPApplication::ASSOC_TYPE_REVIEW_ROUND,
            $roundId,
            $genreId,
            $sourceFile
        );
    }

    public function reviewResultV2(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $serviceMode = trim((string)$illuminateRequest->header('X-OMI-Installation', '')) !== '';
        if ($serviceMode) {
            $context = Application::get()->getRequest()->getContext();
            if (!$context) return $this->error('context_required', 'A press context is required.', 400);
            $serviceError = $this->authorizeServiceRequest($illuminateRequest, (int)$context->getId());
            if ($serviceError) return $serviceError;

            $submissionId = (int)$illuminateRequest->input('submissionExternalId', 0);
            $reviewerId = (int)$illuminateRequest->input('actorExternalId', 0);
            $assignmentId = (int)$illuminateRequest->input('reviewAssignmentExternalId', 0);
            $reviewRoundId = (int)$illuminateRequest->input('reviewRoundExternalId', 0);
            if ($submissionId < 1 || $reviewerId < 1 || $assignmentId < 1 || $reviewRoundId < 1) {
                return $this->error(
                    'invalid_review_result_context',
                    'Service writeback requires submissionExternalId, actorExternalId, reviewAssignmentExternalId and reviewRoundExternalId.',
                    400
                );
            }
            $assignment = $this->reviewAssignmentForServiceWrite(
                $submissionId,
                $assignmentId,
                $reviewerId,
                $reviewRoundId
            );
            if (!$assignment) {
                return $this->error(
                    'review_assignment_forbidden',
                    'The requested review assignment is not writable in the current OMP review round.',
                    403
                );
            }
        } else {
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

        $formResponses = $illuminateRequest->input('reviewFormResponses', []);
        if (!is_array($formResponses)) {
            return $this->error('invalid_review_form_responses', 'Review form responses must be an array.', 400);
        }
        $validatedFormResponses = $this->validateReviewFormResponses($assignment, $formResponses);
        if ($validatedFormResponses instanceof JsonResponse) return $validatedFormResponses;

        $authorComment = trim((string)$illuminateRequest->input('authorAndEditorComment', ''));
        $editorComment = trim((string)$illuminateRequest->input('editorOnlyComment', ''));
        if (
            $authorComment === '' &&
            $editorComment === '' &&
            ($recommendation === null || $recommendation === '') &&
            $validatedFormResponses === []
        ) {
            return $this->error('empty_review_result', 'The review result does not contain writable content.', 400);
        }

        foreach ($validatedFormResponses as $elementId => $value) {
            $this->saveReviewFormResponse($assignment, $elementId, $value);
        }
        if ($authorComment !== '') $this->saveReviewComment($assignment, $authorComment, true);
        if ($editorComment !== '') $this->saveReviewComment($assignment, $editorComment, false);

        return response()->json([
            'protocol' => 'omi-integration/1',
            'profile' => Omp35Adapter::PROFILE,
            'submissionExternalId' => (string)$submissionId,
            'reviewAssignmentExternalId' => (string)$assignment->getId(),
            'reviewRecommendationWritten' => $recommendation !== null && $recommendation !== '',
            'reviewFormResponsesWritten' => count($validatedFormResponses),
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
        if ($uploaderUserId < 1 || !Repo::user()->get($uploaderUserId)) {
            return $this->error('invalid_uploader', 'The signed assertion does not identify a valid OMP user.', 403);
        }

        $temporary = null;
        $upload = $request->file('file');
        if ($upload && $upload->isValid()) {
            $originalName = trim((string)$upload->getClientOriginalName());
            $realPath = $upload->getRealPath();
            if ($realPath === false) {
                return $this->error('upload_unavailable', 'The uploaded temporary file is not available.', 400);
            }
        } else {
            $originalNameValue = $request->input('fileName');
            $base64Value = $request->input('contentBase64');
            if (!is_string($originalNameValue) || !is_string($base64Value)) {
                return $this->error(
                    'file_required',
                    'Supply either a valid multipart file field named file or fileName plus contentBase64.',
                    400
                );
            }

            $originalName = trim($originalNameValue);
            if (
                $originalName === '' ||
                strlen($originalName) > 255 ||
                $originalName === '.' ||
                $originalName === '..' ||
                str_contains($originalName, "\0") ||
                str_contains($originalName, '/') ||
                str_contains($originalName, '\\') ||
                preg_match('/[\x00-\x1F\x7F]/', $originalName)
            ) {
                return $this->error('invalid_file_name', 'The workflow file name is invalid.', 422);
            }

            if (strlen($base64Value) > (int)(ceil(self::MAX_SERVICE_FILE_BYTES / 3) * 4) + 8) {
                return $this->error('file_too_large', 'The workflow file exceeds the 25 MB transfer limit.', 413);
            }
            $bytes = base64_decode($base64Value, true);
            if ($bytes === false || $bytes === '') {
                return $this->error('invalid_file_content', 'The workflow file content is not valid Base64.', 422);
            }
            if (strlen($bytes) > self::MAX_SERVICE_FILE_BYTES) {
                return $this->error('file_too_large', 'The workflow file exceeds the 25 MB transfer limit.', 413);
            }

            $temporary = tmpfile();
            if (!$temporary || fwrite($temporary, $bytes) !== strlen($bytes)) {
                if (is_resource($temporary)) fclose($temporary);
                return $this->error('upload_unavailable', 'OMP could not prepare the workflow file for storage.', 500);
            }
            $realPath = (string)(stream_get_meta_data($temporary)['uri'] ?? '');
            if ($realPath === '') {
                fclose($temporary);
                return $this->error('upload_unavailable', 'The workflow file temporary path is unavailable.', 500);
            }
        }

        if ($originalName === '') $originalName = 'open-manuscript-revision';

        $fileManager = new FileManager();
        $extension = $fileManager->parseFileExtension($originalName);
        $submissionDir = Repo::submissionFile()->getSubmissionDir(
            (int)$submission->getData('contextId'),
            (int)$submission->getId()
        );
        $targetName = uniqid('', true) . ($extension !== '' ? '.' . $extension : '');

        try {
            $fileId = app()->get('file')->add($realPath, $submissionDir . '/' . $targetName);
        } catch (\Throwable) {
            if (is_resource($temporary)) fclose($temporary);
            return $this->error('file_storage_failed', 'OMP could not store the uploaded file.', 500);
        } finally {
            if (is_resource($temporary)) fclose($temporary);
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

    private function authorizeServiceRequest(
        IlluminateRequest $request,
        int $contextId
    ): ?JsonResponse {
        $installation = trim((string)$request->header('X-OMI-Installation', ''));
        $timestamp = trim((string)$request->header('X-OMI-Timestamp', ''));
        $signature = trim((string)$request->header('X-OMI-Signature', ''));
        if ($installation === '' || !ctype_digit($timestamp) || $signature === '') {
            return $this->error(
                'service_authentication_required',
                'Signed OMI service authentication is required.',
                401
            );
        }
        if (abs(time() - (int)$timestamp) > self::SERVICE_CLOCK_SKEW_SECONDS) {
            return $this->error(
                'service_assertion_expired',
                'The OMI service assertion is outside the allowed clock window.',
                401
            );
        }

        $expectedInstallation = $this->plugin->getInstallationId(
            $contextId,
            Application::get()->getRequest()
        );
        if (!hash_equals($expectedInstallation, $installation)) {
            return $this->error(
                'invalid_installation',
                'The OMI installation identifier does not match this press.',
                401
            );
        }

        $secret = (string)$this->plugin->getSetting($contextId, 'sharedSecret');
        if ($secret === '') {
            return $this->error(
                'integration_not_configured',
                'The integration shared secret is not configured.',
                503
            );
        }

        $body = (string)$request->getContent();
        $canonical =
            $timestamp . "\n" .
            strtoupper($request->getMethod()) . "\n" .
            $request->getPathInfo() . "\n" .
            hash('sha256', $body);
        $expected = rtrim(
            strtr(
                base64_encode(hash_hmac('sha256', $canonical, $secret, true)),
                '+/',
                '-_'
            ),
            '='
        );
        if (!hash_equals($expected, $signature)) {
            return $this->error(
                'invalid_service_signature',
                'The OMI service signature is invalid.',
                401
            );
        }
        return null;
    }

    private function reviewAssignmentForServiceWrite(
        int $submissionId,
        int $assignmentId,
        int $reviewerId,
        int $reviewRoundId
    ): ?ReviewAssignment {
        $assignment = Repo::reviewAssignment()->get($assignmentId, $submissionId);
        if (!($assignment instanceof ReviewAssignment)) return null;
        if (
            (int)$assignment->getSubmissionId() !== $submissionId ||
            (int)$assignment->getReviewerId() !== $reviewerId ||
            (int)$assignment->getReviewRoundId() !== $reviewRoundId ||
            $assignment->getCancelled() ||
            $assignment->getDeclined()
        ) {
            return null;
        }

        $submission = Repo::submission()->get($submissionId);
        if (
            !$submission ||
            (int)$submission->getData('stageId') !== (int)$assignment->getStageId()
        ) {
            return null;
        }

        /** @var ReviewRoundDAO $reviewRoundDao */
        $reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');
        $round = $reviewRoundDao->getById($reviewRoundId);
        $latest = $reviewRoundDao->getLastReviewRoundBySubmissionId(
            $submissionId,
            (int)$assignment->getStageId()
        );
        if (
            !($round instanceof ReviewRound) ||
            !($latest instanceof ReviewRound) ||
            (int)$round->getSubmissionId() !== $submissionId ||
            (int)$round->getStageId() !== (int)$assignment->getStageId() ||
            (int)$latest->getId() !== $reviewRoundId ||
            (int)$assignment->getRound() !== (int)$round->getRound()
        ) {
            return null;
        }

        return $assignment;
    }

    private function authorAssignedToCurrentStage(
        int $authorId,
        object $submission,
        object $context
    ): bool {
        if ($authorId < 1 || !Repo::user()->get($authorId)) return false;

        $stageId = (int)$submission->getData('stageId');
        $assignments = Repo::user()->getAccessibleWorkflowStages(
            $authorId,
            (int)$context->getId(),
            $submission
        );
        return in_array(
            Role::ROLE_ID_AUTHOR,
            $assignments[$stageId] ?? [],
            true
        );
    }

    private function reviewRoundAllowsAuthorRevision(ReviewRound $reviewRound): bool
    {
        $stageId = (int)$reviewRound->getStageId();
        $decisionTypes = $stageId === WORKFLOW_STAGE_ID_EXTERNAL_REVIEW
            ? [
                Decision::ACCEPT,
                Decision::PENDING_REVISIONS,
                Decision::NEW_EXTERNAL_ROUND,
                Decision::RESUBMIT,
            ]
            : ($stageId === WORKFLOW_STAGE_ID_INTERNAL_REVIEW
                ? [
                    Decision::ACCEPT_INTERNAL,
                    Decision::PENDING_REVISIONS_INTERNAL,
                    Decision::NEW_INTERNAL_ROUND,
                    Decision::RESUBMIT_INTERNAL,
                ]
                : []);

        if ($decisionTypes === []) return false;

        return Repo::decision()->getCollector()
            ->filterBySubmissionIds([(int)$reviewRound->getSubmissionId()])
            ->filterByStageIds([$stageId])
            ->filterByReviewRoundIds([(int)$reviewRound->getId()])
            ->filterByDecisionTypes($decisionTypes)
            ->getCount() > 0;
    }

    private function validateReviewFormResponses(
        ReviewAssignment $assignment,
        array $responses
    ): array|JsonResponse {
        $formId = (int)$assignment->getData('reviewFormId');
        if ($responses !== [] && $formId < 1) {
            return $this->error(
                'review_form_not_assigned',
                'This review assignment does not use a review form.',
                400
            );
        }
        if ($formId < 1) return [];

        /** @var \PKP\reviewForm\ReviewFormElementDAO $elementDao */
        $elementDao = DAORegistry::getDAO('ReviewFormElementDAO');
        /** @var \PKP\reviewForm\ReviewFormResponseDAO $responseDao */
        $responseDao = DAORegistry::getDAO('ReviewFormResponseDAO');
        $existing = $responseDao->getReviewReviewFormResponseValues($assignment->getId());
        $validated = [];

        foreach ($responses as $response) {
            if (!is_array($response)) {
                return $this->error(
                    'invalid_review_form_response',
                    'Each review form response must be an object.',
                    400
                );
            }
            $elementId = (int)($response['elementExternalId'] ?? 0);
            if ($elementId < 1 || array_key_exists($elementId, $validated)) {
                return $this->error(
                    'invalid_review_form_element',
                    'Review form element identifiers must be valid and unique.',
                    400
                );
            }
            $element = $elementDao->getById($elementId, $formId);
            if (!($element instanceof ReviewFormElement)) {
                return $this->error(
                    'review_form_element_forbidden',
                    'A response references an element outside the assigned review form.',
                    403
                );
            }
            $normalized = $this->normalizeReviewFormValue(
                $element,
                $response['value'] ?? null
            );
            if ($normalized instanceof JsonResponse) return $normalized;
            $validated[$elementId] = $normalized;
        }

        foreach ($elementDao->getRequiredReviewFormElementIds($formId) as $requiredId) {
            $value = array_key_exists((int)$requiredId, $validated)
                ? $validated[(int)$requiredId]
                : ($existing[(int)$requiredId] ?? null);
            if ($this->reviewFormValueEmpty($value)) {
                return $this->error(
                    'review_form_required',
                    'All required OMP review form fields must be completed before submission.',
                    400
                );
            }
        }
        return $validated;
    }

    private function normalizeReviewFormValue(
        ReviewFormElement $element,
        mixed $value
    ): mixed {
        $type = (int)$element->getElementType();
        $possible = $element->getLocalizedPossibleResponses();
        $allowed = is_array($possible)
            ? array_map('strval', array_keys($possible))
            : [];

        if ($type === ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_CHECKBOXES) {
            if (!is_array($value)) {
                return $this->error(
                    'invalid_review_form_value',
                    'Checkbox responses must be arrays.',
                    400
                );
            }
            $values = array_values(array_unique(array_map('strval', $value)));
            foreach ($values as $item) {
                if (!in_array($item, $allowed, true)) {
                    return $this->error(
                        'invalid_review_form_option',
                        'A checkbox response contains an invalid option.',
                        400
                    );
                }
            }
            return $values;
        }

        if (in_array(
            $type,
            [
                ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_RADIO_BUTTONS,
                ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_DROP_DOWN_BOX,
            ],
            true
        )) {
            if (!is_scalar($value) && $value !== null) {
                return $this->error(
                    'invalid_review_form_value',
                    'Choice responses must contain one option.',
                    400
                );
            }
            $scalar = $value === null ? '' : (string)$value;
            if ($scalar !== '' && !in_array($scalar, $allowed, true)) {
                return $this->error(
                    'invalid_review_form_option',
                    'The selected review form option is invalid.',
                    400
                );
            }
            return $scalar;
        }

        if (!in_array(
            $type,
            [
                ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_SMALL_TEXT_FIELD,
                ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_TEXT_FIELD,
                ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_TEXTAREA,
            ],
            true
        )) {
            return $this->error(
                'unsupported_review_form_element',
                'The assigned OMP review form contains an unsupported element type.',
                400
            );
        }

        if (!is_scalar($value) && $value !== null) {
            return $this->error(
                'invalid_review_form_value',
                'Text review form responses must be text.',
                400
            );
        }
        $text = $value === null ? '' : (string)$value;
        if (mb_strlen($text) > 100000) {
            return $this->error(
                'review_form_value_too_long',
                'A review form response exceeds the supported length.',
                400
            );
        }
        return $text;
    }

    private function reviewFormValueEmpty(mixed $value): bool
    {
        if (is_array($value)) return $value === [];
        return trim((string)($value ?? '')) === '';
    }

    private function saveReviewFormResponse(
        ReviewAssignment $assignment,
        int $elementId,
        mixed $value
    ): void {
        /** @var \PKP\reviewForm\ReviewFormElementDAO $elementDao */
        $elementDao = DAORegistry::getDAO('ReviewFormElementDAO');
        /** @var \PKP\reviewForm\ReviewFormResponseDAO $responseDao */
        $responseDao = DAORegistry::getDAO('ReviewFormResponseDAO');
        $element = $elementDao->getById(
            $elementId,
            (int)$assignment->getReviewFormId()
        );
        if (!($element instanceof ReviewFormElement)) return;

        $response = $responseDao->getReviewFormResponse(
            (int)$assignment->getId(),
            $elementId
        ) ?? new ReviewFormResponse();

        $responseType = match ((int)$element->getElementType()) {
            ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_CHECKBOXES => 'object',
            ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_RADIO_BUTTONS,
            ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_DROP_DOWN_BOX => 'int',
            default => 'string',
        };
        $response->setResponseType($responseType);
        $response->setValue($value);

        if (
            $response->getReviewId() !== null &&
            $response->getReviewFormElementId() !== null
        ) {
            $responseDao->updateObject($response);
            return;
        }
        $response->setReviewId((int)$assignment->getId());
        $response->setReviewFormElementId($elementId);
        $responseDao->insertObject($response);
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
