<?php
namespace APP\plugins\generic\studioIntegration;

use APP\core\Application;
use APP\plugins\generic\studioIntegration\classes\Core\ApiResponse;

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
                'version' => '1.1.0',
            ],
            'capabilities' => [
                'launch',
            ],
            'plannedCapabilities' => [
                'metadata.read',
                'contributors.read',
                'files.read',
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
}
