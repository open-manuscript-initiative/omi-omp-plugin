<?php
declare(strict_types=1);

namespace PKP\plugins { class GenericPlugin {} }
namespace PKP\core { class PKPBaseController {} }
namespace APP\handler { class Handler {} }
namespace {
    $root = dirname(__DIR__);
    $cwd = getcwd();
    chdir(sys_get_temp_dir());
    try {
        // The plugin must work even when PKP does not autoload its helper classes.
        require_once $root . '/StudioIntegrationPlugin.php';
        require_once $root . '/StudioIntegrationApiController.php';
        require_once $root . '/StudioIntegrationNativeApiController.php';
        require_once $root . '/StudioIntegrationApiHandler.php';
        $prefix = 'APP\\plugins\\generic\\studioIntegration\\classes\\';
        foreach ([
            'Adapters\\Omp35Adapter',
            'Core\\Base64Url',
            'Core\\LaunchToken',
            'Core\\WorkflowSnapshot',
            'Core\\MetadataReconciliation',
            'Core\\WorkflowFileDocument',
            'Core\\HtmlPublicationDocument',
            'Core\\PublicationArtifactDocument',
        ] as $suffix) {
            if (!class_exists($prefix . $suffix)) {
                throw new \RuntimeException('Missing plugin class: ' . $suffix);
            }
        }
        $adapter = $prefix . 'Adapters\\Omp35Adapter';
        if ($adapter::PROFILE !== 'omi-integration/1/omp') {
            throw new \RuntimeException('Incorrect OMP profile.');
        }
        $token = $prefix . 'Core\\LaunchToken';
        $issued = $token::issue(['context' => ['externalId' => '1']], 'test-only-secret');
        if (!$issued['payload'] || !$issued['signature']) {
            throw new \RuntimeException('Launch token helper did not load.');
        }
    } finally {
        chdir($cwd);
    }
    echo "OMP plugin helper class loading passed\n";
}
