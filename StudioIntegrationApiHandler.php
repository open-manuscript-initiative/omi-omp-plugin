<?php
namespace APP\plugins\generic\studioIntegration;

use APP\handler\Handler;
use PKP\core\JSONMessage;

class StudioIntegrationApiHandler extends Handler
{
    private StudioIntegrationPlugin $plugin;

    public function __construct(StudioIntegrationPlugin $plugin)
    {
        parent::__construct();
        $this->plugin = $plugin;
    }

    public function launch(array $args, $request)
    {
        $submissionId = filter_var(
            $request->getUserVar('submissionId'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if (!$submissionId) {
            return new JSONMessage(false, [
                'error' => [
                    'code' => 'INVALID_SUBMISSION_ID',
                    'message' => 'A valid monograph submissionId is required.',
                ],
            ]);
        }

        $requestedMode = (string)$request->getUserVar('mode');
        $resolvedMode = $this->plugin->resolveLaunchMode($request, $requestedMode);
        if ($resolvedMode === 'review') {
            $launchUrl = $this->plugin->createReviewerLaunchUrl($request, $submissionId);
            if ($launchUrl !== null) {
                $launchUrl = $this->reviewWorkspaceLaunchUrl($launchUrl);
            }
        } elseif ($resolvedMode === 'author') {
            $launchUrl = $this->plugin->createLaunchUrl($request, $submissionId, 'author');
        } else {
            $launchUrl = $this->plugin->createLaunchUrl($request, $submissionId, 'editor');
        }

        if ($launchUrl === null) {
            return new JSONMessage(false, [
                'error' => [
                    'code' => 'LAUNCH_FORBIDDEN',
                    'message' => 'The current user cannot launch this monograph in Open Manuscript Studio for the requested role.',
                ],
            ]);
        }

        if ((string)$request->getUserVar('redirect') === '1') {
            $this->sendDirectRedirect($launchUrl);
        }

        return new JSONMessage(
            true,
            ['launchUrl' => $launchUrl, 'mode' => $resolvedMode],
            '0',
            ['launchUrl' => $launchUrl, 'mode' => $resolvedMode]
        );
    }

    private function reviewWorkspaceLaunchUrl(string $launchUrl): string
    {
        $marker = '/integrations/omp/launch?';
        if (!str_contains($launchUrl, $marker)) {
            return $launchUrl;
        }
        return str_replace(
            $marker,
            '/?review=1&ompReviewLaunch=1&',
            $launchUrl
        );
    }

    private function sendDirectRedirect(string $launchUrl): void
    {
        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
        header('Referrer-Policy: no-referrer');
        header('Location: ' . $launchUrl, true, 303);
        exit;
    }
}
