<?php
declare(strict_types=1);

function failCompatibility(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../StudioIntegrationNativeApiController.php');
if ($source === false) {
    failCompatibility('Unable to read StudioIntegrationNativeApiController.php.');
}

if (!str_contains($source, "method_exists(\$application, 'hasCustomizableReviewerRecommendation')")) {
    failCompatibility('OMP reviewer recommendation capability is not feature-detected.');
}

$helperStart = strpos($source, 'private function supportsCustomizableReviewerRecommendations');
$helperEnd = $helperStart === false
    ? false
    : strpos($source, 'private function authorizeServiceRequest', $helperStart);
$helper = $helperStart === false
    ? ''
    : substr(
        $source,
        $helperStart,
        $helperEnd === false ? null : $helperEnd - $helperStart
    );

if (!str_contains($helper, 'method_exists')) {
    failCompatibility('Reviewer recommendation support must default safely when the OMP method is absent.');
}

$outside = str_replace($helper, '', $source);
if (str_contains($outside, '->hasCustomizableReviewerRecommendation()')) {
    failCompatibility('Direct reviewer recommendation capability calls bypass the OMP 3.5.0-4 compatibility guard.');
}

echo "OMP 3.5.0-4 reviewer recommendation compatibility checks passed\n";
