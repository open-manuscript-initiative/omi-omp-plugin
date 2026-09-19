<?php
declare(strict_types=1);

function failNativeClientContract(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../StudioIntegrationNativeApiController.php');
if ($source === false) failNativeClientContract('Unable to read native OMP controller.');

foreach ([
    "Route::get('author-context'",
    "'serviceWriteback'",
    "'authentication' => 'omi-hmac-sha256'",
    "Route::post('review-attachments'",
    "Route::post('author-revisions'",
    "Route::post('review-result-v2'",
    "private function authorizeServiceRequest",
    "X-OMI-Installation",
    "X-OMI-Timestamp",
    "X-OMI-Signature",
    "private function reviewAssignmentForServiceWrite",
    "private function authorAssignedToCurrentStage",
    "private function reviewRoundAllowsAuthorRevision",
    "Decision::PENDING_REVISIONS",
    "Decision::PENDING_REVISIONS_INTERNAL",
    "source_file_forbidden",
    "SubmissionFileAccessPolicy::SUBMISSION_FILE_ACCESS_READ",
    "reviewFormResponses",
    "private function validateReviewFormResponses",
    "private function saveReviewFormResponse",
    "contentBase64",
    "MAX_SERVICE_FILE_BYTES",
] as $required) {
    if (!str_contains($source, $required)) {
        failNativeClientContract("Native OMP Studio contract is missing: {$required}");
    }
}

$reviewResultStart = strpos($source, 'public function reviewResultV2');
$storeStart = strpos($source, 'private function storeUploadedSubmissionFile', $reviewResultStart ?: 0);
$reviewResult = $reviewResultStart === false
    ? ''
    : substr($source, $reviewResultStart, $storeStart === false ? null : $storeStart - $reviewResultStart);

if (!str_contains($reviewResult, "'reviewCompleted' => false")) {
    failNativeClientContract('Studio writeback must not complete the native OMP review workflow.');
}
if (str_contains($reviewResult, 'dateCompleted')) {
    // Reading completion state is allowed; directly setting it is not.
    if (preg_match('/setData\s*\(\s*[\'\"]dateCompleted/', $reviewResult)) {
        failNativeClientContract('Studio must not set OMP review completion state.');
    }
}

$authorStart = strpos($source, 'public function uploadAuthorRevision');
$reviewResultStart = strpos($source, 'public function reviewResultV2', $authorStart ?: 0);
$authorBlock = $authorStart === false
    ? ''
    : substr($source, $authorStart, $reviewResultStart === false ? null : $reviewResultStart - $authorStart);

foreach ([
    'reviewRoundAllowsAuthorRevision',
    'getLastReviewRoundBySubmissionId',
    'authorAssignedToCurrentStage',
] as $required) {
    if (!str_contains($authorBlock, $required)) {
        failNativeClientContract("Author revision boundary is missing: {$required}");
    }
}

echo "Native OMP Studio client contract checks passed\n";
