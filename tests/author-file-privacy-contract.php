<?php
declare(strict_types=1);

function failAuthorBoundary(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$controller = file_get_contents(__DIR__ . '/../StudioIntegrationApiController.php');
if ($controller === false) {
    failAuthorBoundary('Unable to read StudioIntegrationApiController.php.');
}

$helperStart = strpos($controller, 'private function authorReadableFileStages');
$helperEnd = $helperStart === false
    ? false
    : strpos($controller, 'private function reviewAssignmentForClaims', $helperStart);
$helper = $helperStart === false
    ? ''
    : substr(
        $controller,
        $helperStart,
        $helperEnd === false ? null : $helperEnd - $helperStart
    );

foreach ([
    "actorMode'] ?? '') !== 'author'",
    'getAccessibleWorkflowStages',
    'Role::ROLE_ID_AUTHOR',
    "authorAssignments[(int)\$stageId] = [Role::ROLE_ID_AUTHOR]",
    'getAssignedFileStages',
    'SubmissionFileAccessPolicy::SUBMISSION_FILE_ACCESS_READ',
] as $required) {
    if (!str_contains($helper, $required)) {
        failAuthorBoundary("Author file-stage resolver is missing: {$required}");
    }
}

$filesStart = strpos($controller, 'public function files(');
$filesEnd = $filesStart === false
    ? false
    : strpos($controller, 'public function reviewForm(', $filesStart);
$filesBlock = $filesStart === false
    ? ''
    : substr(
        $controller,
        $filesStart,
        $filesEnd === false ? null : $filesEnd - $filesStart
    );

if (
    !str_contains($filesBlock, "actorMode'] ?? '') === 'author'") ||
    !str_contains($filesBlock, 'authorReadableFileStages') ||
    !str_contains($filesBlock, "(int)(\$file['stage'] ?? 0)")
) {
    failAuthorBoundary('Author file listings are not filtered through native PKP file stages.');
}

$contentStart = strpos($controller, 'public function fileContent(');
$contentEnd = $contentStart === false
    ? false
    : strpos($controller, 'public function reviewResult(', $contentStart);
$contentBlock = $contentStart === false
    ? ''
    : substr(
        $controller,
        $contentStart,
        $contentEnd === false ? null : $contentEnd - $contentStart
    );

if (
    !str_contains($contentBlock, "actorMode'] ?? '') === 'author'") ||
    !str_contains($contentBlock, 'authorReadableFileStages') ||
    !str_contains($contentBlock, "getData('fileStage')") ||
    !str_contains($contentBlock, "'file_not_available_for_author'")
) {
    failAuthorBoundary('Direct author file downloads do not enforce the native file-stage boundary.');
}

if (str_contains($helper, 'ROLE_ID_MANAGER') || str_contains($helper, 'ROLE_ID_SUB_EDITOR')) {
    failAuthorBoundary('Author-mode file resolution must not inherit editorial roles from a dual-role account.');
}

echo "OMP author file privacy contract checks passed\n";
