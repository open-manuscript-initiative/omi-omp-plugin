<?php
declare(strict_types=1);

function failContract(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$plugin = file_get_contents(__DIR__ . '/../StudioIntegrationPlugin.php');
$primary = file_get_contents(__DIR__ . '/../StudioIntegrationApiController.php');
$native = file_get_contents(__DIR__ . '/../StudioIntegrationNativeApiController.php');

if ($plugin === false || $primary === false || $native === false) {
    failContract('Unable to read OMP integration controller sources.');
}

$registrationStart = strpos($plugin, 'public function registerApiController');
$registrationEnd = $registrationStart === false
    ? false
    : strpos($plugin, 'public function ', $registrationStart + 32);
$registration = $registrationStart === false
    ? ''
    : substr(
        $plugin,
        $registrationStart,
        $registrationEnd === false ? null : $registrationEnd - $registrationStart
    );

if (substr_count($registration, 'new StudioIntegrationApiController($this)') !== 1) {
    failContract('The OMP plugin must register exactly one primary API controller.');
}
if (str_contains($registration, 'new StudioIntegrationNativeApiController($this)')) {
    failContract('The native OMP controller must not be registered as a second APIRouter handler.');
}
if (!str_contains($primary, '(new StudioIntegrationNativeApiController($this->plugin))->getGroupRoutes();')) {
    failContract('The primary OMP controller is not composing the native route set.');
}

foreach ([$primary, $native] as $source) {
    if (!preg_match("/return\s+'omi-integration';/", $source)) {
        failContract('An OMP integration controller changed the public handler path.');
    }
}

foreach ([
    "Route::get('platform-capabilities'",
    "Route::get('review-context'",
    "Route::get('review-attachments'",
    "Route::post('review-attachments'",
    "Route::post('author-revisions'",
    "Route::post('review-result-v2'",
] as $route) {
    if (!str_contains($native, $route)) {
        failContract("The native OMP route set is missing: {$route}");
    }
}

echo "OMP API route registration contract checks passed\n";
