<?php
$controller = file_get_contents(__DIR__ . '/../StudioIntegrationApiController.php');
if ($controller === false) {
    throw new RuntimeException('Unable to read StudioIntegrationApiController.php');
}

$required = [
    "Route::post('publication-artifact'",
    "PublicationArtifactDocument::PROTOCOL",
    "WORKFLOW_STAGE_ID_PRODUCTION",
    "Submission::STATUS_QUEUED",
    "->lockForUpdate()",
    "Application::ASSOC_TYPE_PUBLICATION_FORMAT",
    "SubmissionFile::SUBMISSION_FILE_PROOF",
    "setIsApproved(false)",
    "setIsAvailable(false)",
    "'viewable' => false",
    "'editor.publication-artifact.write'",
    "'publication.provenance.verify'",
    "'formatApprovedByDefault' => false",
    "'formatAvailableByDefault' => false",
    "'proofViewableByDefault' => false",
    "already approved, available, or viewable",
];

foreach ($required as $needle) {
    if (!str_contains($controller, $needle)) {
        throw new RuntimeException('Missing publication transfer contract: ' . $needle);
    }
}

foreach ([
    "setIsApproved(true)",
    "setIsAvailable(true)",
    "'viewable' => true",
] as $unsafe) {
    $methodStart = strpos($controller, 'public function publicationArtifact');
    $methodEnd = strpos($controller, '/** Public submission requirements', $methodStart);
    $method = substr($controller, $methodStart, $methodEnd - $methodStart);
    if (str_contains($method, $unsafe)) {
        throw new RuntimeException('Publication transfer must not auto-approve OMP output: ' . $unsafe);
    }
}

echo "OMP publication artifact controller contract checks passed\n";
