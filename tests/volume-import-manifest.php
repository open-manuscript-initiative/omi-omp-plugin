<?php
namespace PKP\submissionFile {
    class SubmissionFile {
        const SUBMISSION_FILE_PRODUCTION_READY = 10;
        const SUBMISSION_FILE_COPYEDIT = 11;
        const SUBMISSION_FILE_FINAL = 12;
        const SUBMISSION_FILE_SUBMISSION = 13;
    }
}
namespace {
    require_once __DIR__ . '/../classes/Adapters/Omp35Adapter.php';
    use APP\plugins\generic\studioIntegration\classes\Adapters\Omp35Adapter;
    $file = static fn ($id, $stage, $genre, $chapter, $name = 'source.docx') => [
        'externalId' => (string)$id, 'stage' => $stage, 'genreKey' => $genre,
        'chapterExternalId' => $chapter, 'name' => $name,
    ];
    $files = [
        $file(1, 13, 'MANUSCRIPT', null),
        $file(2, 10, 'CHAPTER', '7'),
        $file(3, 99, 'CHAPTER', '7'),
        $file(4, 10, 'CHAPTER', '8'),
        $file(5, 10, 'CHAPTER', null),
        $file(6, 10, 'MANUSCRIPT', '7'),
        $file(7, 10, 'CHAPTER', '7', 'source.pdf'),
        $file(8, 10, 'APPENDIX', null),
        $file(9, 11, 'CHAPTER', '7'),
    ];
    $result = Omp35Adapter::volumeSourceDescriptors($files, ['7']);
    if (array_column($result, 'externalId') !== ['2', '9', '1']) {
        throw new \RuntimeException('Volume source eligibility or priority is incorrect.');
    }
    $controller = file_get_contents(__DIR__ . '/../StudioIntegrationApiController.php');
    $start = strpos($controller, 'public function volumeImportManifest');
    $end = strpos($controller, 'public function submission(', $start);
    $handler = substr($controller, $start, $end - $start);
    foreach (['getAccessibleWorkflowStages', 'Role::ROLE_ID_MANAGER', 'Role::ROLE_ID_SITE_ADMIN',
        'Submission::STATUS_QUEUED', 'currentPublicationId', 'private, no-store',
        "'binaryTransfer' => ['available' => false]"] as $guard) {
        if (!str_contains($handler, $guard)) {
            throw new \RuntimeException('Missing volume manifest boundary: ' . $guard);
        }
    }
    echo "OMP volume manifest contract passed.\n";
}
