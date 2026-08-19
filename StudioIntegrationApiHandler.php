<?php
namespace APP\plugins\generic\studioIntegration;

use APP\facades\Repo;
use APP\plugins\generic\studioIntegration\classes\Core\ApiResponse;
use APP\plugins\generic\studioIntegration\classes\Core\LaunchToken;

class StudioIntegrationApiHandler extends \APP\handler\Handler
{
    public function __construct(private StudioIntegrationPlugin $plugin)
    {
    }

    public function index($args, $request)
    {
        return $this->capabilities($args, $request);
    }

    public function capabilities($args, $request)
    {
        ApiResponse::send([
            'protocol' => 'omi-integration/1',
            'profile' => 'omi-integration/1/omp',
            'implementation' => [
                'name' => 'OMI OMP Integration Plugin',
                'version' => '1.2.0',
            ],
            'capabilities' => [
                'launch',
                'metadata.read',
                'contributors.read',
                'files.read',
            ],
            'plannedCapabilities' => [
                'manuscript.read',
                'manuscript.write',
                'review.read',
                'review.write',
                'revision.write',
                'publication.read',
                'publication.export',
            ],
        ]);
        return null;
    }

    public function context($args, $request)
    {
        $context = $request->getContext();
        if (!$context) {
            ApiResponse::error('context_required', 'A press context is required.', 400);
            return null;
        }
        ApiResponse::send([
            'installationId' => $this->plugin->getInstallationId($context->getId(), $request),
            'context' => [
                'externalId' => (string)$context->getId(),
                'type' => 'press',
                'path' => $context->getPath(),
                'name' => $context->getData('name'),
                'url' => $request->url($context->getPath()),
            ],
        ]);
        return null;
    }

    public function metadata($args, $request)
    {
        $claims = $this->authorizeRead($request, 'metadata.read');
        if (!$claims) {
            return null;
        }

        $submission = $this->loadSubmission($claims, $request);
        if (!$submission) {
            return null;
        }
        $publication = $submission->getCurrentPublication();

        ApiResponse::send([
            'protocol' => 'omi-integration/1',
            'profile' => 'omi-integration/1/omp',
            'submission' => [
                'externalId' => (string)$submission->getId(),
                'type' => 'monograph',
                'primaryLocale' => $submission->getData('locale'),
                'status' => $submission->getData('status'),
                'stageId' => $submission->getData('stageId'),
                'title' => $publication->getData('title') ?: [],
                'subtitle' => $publication->getData('subtitle') ?: [],
                'abstract' => $publication->getData('abstract') ?: [],
                'keywords' => $publication->getData('keywords') ?: [],
                'prefix' => $publication->getData('prefix') ?: [],
                'datePublished' => $publication->getData('datePublished'),
                'publicationId' => (string)$publication->getId(),
                'updatedAt' => $submission->getData('lastModified'),
            ],
        ]);
        return null;
    }

    public function contributors($args, $request)
    {
        $claims = $this->authorizeRead($request, 'contributors.read');
        if (!$claims) {
            return null;
        }

        $submission = $this->loadSubmission($claims, $request);
        if (!$submission) {
            return null;
        }
        $publication = $submission->getCurrentPublication();
        $authors = $publication->getData('authors') ?: [];
        $contributors = [];

        foreach ($authors as $index => $author) {
            $contributors[] = [
                'externalId' => (string)$author->getId(),
                'name' => [
                    'given' => $author->getGivenName(),
                    'family' => $author->getFamilyName(),
                ],
                'email' => $author->getEmail(),
                'affiliation' => $author->getAffiliation(),
                'country' => $author->getData('country'),
                'sequence' => $index + 1,
                'primaryContact' => (bool)$author->getData('primaryContact'),
                'isEditor' => method_exists($author, 'getIsEditor') ? (bool)$author->getIsEditor() : false,
                'identifiers' => array_values(array_filter([
                    $author->getOrcid() ? ['scheme' => 'orcid', 'value' => $author->getOrcid()] : null,
                ])),
            ];
        }

        ApiResponse::send([
            'protocol' => 'omi-integration/1',
            'profile' => 'omi-integration/1/omp',
            'submissionExternalId' => (string)$submission->getId(),
            'contributors' => $contributors,
        ]);
        return null;
    }

    public function files($args, $request)
    {
        $claims = $this->authorizeRead($request, 'files.read');
        if (!$claims) {
            return null;
        }

        $submission = $this->loadSubmission($claims, $request);
        if (!$submission) {
            return null;
        }

        $files = [];
        $submissionFiles = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([$submission->getId()])
            ->getMany();

        foreach ($submissionFiles as $submissionFile) {
            $files[] = [
                'externalId' => (string)$submissionFile->getId(),
                'fileId' => (string)$submissionFile->getData('fileId'),
                'name' => $submissionFile->getData('name') ?: $submissionFile->getData('originalFileName'),
                'mediaType' => $submissionFile->getData('mimetype'),
                'fileStage' => $submissionFile->getData('fileStage'),
                'genreId' => $submissionFile->getData('genreId'),
                'assocType' => $submissionFile->getData('assocType'),
                'assocId' => $submissionFile->getData('assocId'),
                'createdAt' => $submissionFile->getData('createdAt'),
                'updatedAt' => $submissionFile->getData('updatedAt'),
            ];
        }

        ApiResponse::send([
            'protocol' => 'omi-integration/1',
            'profile' => 'omi-integration/1/omp',
            'submissionExternalId' => (string)$submission->getId(),
            'files' => $files,
        ]);
        return null;
    }

    private function authorizeRead($request, string $requiredScope): ?array
    {
        $context = $request->getContext();
        if (!$context) {
            ApiResponse::error('context_required', 'A press context is required.', 400);
            return null;
        }

        $payload = trim((string)$request->getUserVar('payload'));
        $signature = trim((string)$request->getUserVar('signature'));
        if ($payload === '' || $signature === '') {
            ApiResponse::error('missing_assertion', 'A signed OMI assertion is required.', 401);
            return null;
        }

        $claims = LaunchToken::verify(
            $payload,
            $signature,
            $this->plugin->getSharedSecret($context->getId()),
            $context->getId()
        );
        if (!$claims || ($claims['profile'] ?? null) !== 'omi-integration/1/omp') {
            ApiResponse::error('invalid_assertion', 'The OMI assertion is invalid or expired.', 401);
            return null;
        }
        if (!in_array($requiredScope, (array)($claims['scope'] ?? []), true)) {
            ApiResponse::error('scope_denied', 'The OMI assertion does not grant the required scope.', 403);
            return null;
        }
        return $claims;
    }

    private function loadSubmission(array $claims, $request)
    {
        $submissionId = (int)($claims['submission']['externalId'] ?? 0);
        if ($submissionId < 1) {
            ApiResponse::error('submission_required', 'A monograph submission is required.', 400);
            return null;
        }

        $submission = Repo::submission()->get($submissionId);
        $context = $request->getContext();
        if (!$submission || !$context || (int)$submission->getData('contextId') !== (int)$context->getId()) {
            ApiResponse::error('submission_not_found', 'The monograph was not found in this press.', 404);
            return null;
        }
        return $submission;
    }
}
