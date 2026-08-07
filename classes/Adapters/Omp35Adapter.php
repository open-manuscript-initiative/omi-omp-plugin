<?php
namespace APP\plugins\generic\studioIntegration\classes\Adapters;

final class Omp35Adapter
{
    public const PROFILE = 'omi-integration/1/omp';

    public function mapContext($context, $request): array
    {
        return [
            'externalId' => (string)$context->getId(),
            'type' => 'press',
            'path' => $context->getPath(),
            'name' => $context->getData('name'),
            'url' => $request->url($context->getPath()),
        ];
    }

    public function mapSubmissionIdentity($submission): array
    {
        return [
            'externalId' => (string)$submission->getId(),
            'type' => 'monograph',
        ];
    }

    public function mapComponentIdentity($component): array
    {
        return [
            'externalId' => (string)$component->getId(),
            'type' => 'component',
        ];
    }
}
