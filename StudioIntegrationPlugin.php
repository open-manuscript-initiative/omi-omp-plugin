<?php
namespace APP\plugins\generic\studioIntegration;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\studioIntegration\classes\Core\LaunchToken;
use APP\plugins\generic\studioIntegration\classes\StudioIntegrationSettingsForm;
use PKP\core\APIRouter;
use PKP\core\JSONMessage;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;
use PKP\submission\reviewAssignment\ReviewAssignment;

class StudioIntegrationPlugin extends GenericPlugin
{
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('LoadHandler', $this->loadApiHandler(...));
            Hook::add('APIHandler::endpoints::plugin', $this->registerApiController(...));
        }
        return $success;
    }

    public function getDisplayName(): string
    {
        return __('plugins.generic.studioIntegration.displayName');
    }

    public function getDescription(): string
    {
        return __('plugins.generic.studioIntegration.description');
    }

    public function getActions($request, $verb): array
    {
        $router = $request->getRouter();
        return array_merge($this->getEnabled() ? [
            new LinkAction(
                'settings',
                new AjaxModal(
                    $router->url($request, null, null, 'manage', null, [
                        'verb' => 'settings',
                        'plugin' => $this->getName(),
                        'category' => 'generic',
                    ]),
                    $this->getDisplayName()
                ),
                __('manager.plugins.settings'),
                null
            ),
        ] : [], parent::getActions($request, $verb));
    }

    public function manage($args, $request)
    {
        if ($request->getUserVar('verb') !== 'settings') {
            return parent::manage($args, $request);
        }
        $context = $request->getContext();
        $contextId = $context ? $context->getId() : PKPApplication::SITE_CONTEXT_ID;
        $form = new StudioIntegrationSettingsForm($this, $contextId);
        if ($request->getUserVar('save')) {
            $form->readInputData();
            if ($form->validate()) {
                $form->execute();
                return new JSONMessage(true);
            }
        } else {
            $form->initData();
        }
        return new JSONMessage(true, $form->fetch($request));
    }

    public function loadApiHandler(string $hookName, array $args): bool
    {
        $page = &$args[0];
        $handler = &$args[3];
        if ($page !== 'omiIntegration') return false;
        require_once($this->getPluginPath() . '/StudioIntegrationApiHandler.php');
        $handler = new StudioIntegrationApiHandler($this);
        return true;
    }

    public function registerApiController(string $hookName, APIRouter $apiRouter): bool
    {
        require_once($this->getPluginPath() . '/StudioIntegrationApiController.php');
        $apiRouter->registerPluginApiControllers([
            new StudioIntegrationApiController($this),
        ]);
        return Hook::CONTINUE;
    }

    public function resolveLaunchMode($request, string $requestedMode): string
    {
        $context = $request->getContext();
        $user = $request->getUser();
        if (!$context || !$user) return 'editor';
        if ($this->isEditorialUser($user, $context)) return 'editor';
        if ($requestedMode === 'review' && $user->hasRole([Role::ROLE_ID_REVIEWER], $context->getId())) return 'review';
        if ($user->hasRole([Role::ROLE_ID_AUTHOR], $context->getId())) return 'author';
        if ($user->hasRole([Role::ROLE_ID_REVIEWER], $context->getId())) return 'review';
        return 'editor';
    }

    public function createLaunchUrl($request, int $submissionId, string $mode = 'editor'): ?string
    {
        $context = $request->getContext();
        $user = $request->getUser();
        if (!$context || !$user || $submissionId < 1) return null;

        if ($mode === 'editor') {
            if (!$this->isEditorialUser($user, $context)) return null;
        } elseif ($mode === 'author') {
            if (!$user->hasRole([Role::ROLE_ID_AUTHOR], $context->getId())) return null;
        } else {
            return null;
        }
        if (!$this->userCanAccessSubmission($user, $context, $submissionId)) return null;

        $contextId = (int)$context->getId();
        $studioUrl = rtrim(trim((string)$this->getSetting($contextId, 'studioUrl')), '/');
        if ($studioUrl === '') return null;
        $secret = $this->getSharedSecret($contextId);
        if ($secret === '') return null;

        $ttl = $this->getTokenTtl($contextId);
        $now = time();
        $apiBaseUrl = $request->getDispatcher()->url(
            $request,
            Application::ROUTE_API,
            $context->getPath(),
            'omi-integration'
        );

        $scope = $mode === 'editor'
            ? [
                'metadata.read',
                'contributors.read',
                'reviewers.read',
                'files.read',
                'review.identity.read',
            ]
            : [
                'metadata.read',
                'files.read',
            ];

        $claims = [
            'protocol' => 'omi-integration/1',
            'profile' => 'omi-integration/1/omp',
            'installationId' => $this->getInstallationId($contextId, $request),
            'context' => [
                'externalId' => (string)$contextId,
                'type' => 'press',
                'path' => $context->getPath(),
            ],
            'submission' => ['externalId' => (string)$submissionId],
            'actor' => ['externalId' => (string)$user->getId()],
            'actorMode' => $mode,
            'scope' => $scope,
            'iat' => $now,
            'exp' => $now + $ttl,
            'nonce' => bin2hex(random_bytes(16)),
            'externalBaseUrl' => $request->getBaseUrl(),
            'apiBaseUrl' => $apiBaseUrl,
        ];

        try {
            $token = LaunchToken::issue($claims, $secret);
        } catch (\Throwable) {
            return null;
        }

        return $studioUrl . '/integrations/omp/launch?' . http_build_query($token, '', '&', PHP_QUERY_RFC3986);
    }

    public function createReviewerLaunchUrl($request, int $submissionId): ?string
    {
        $context = $request->getContext();
        $user = $request->getUser();
        if (!$context || !$user || $submissionId < 1) return null;
        if (!$user->hasRole([Role::ROLE_ID_REVIEWER], $context->getId())) return null;

        $submission = Repo::submission()->get($submissionId, $context->getId());
        if (!$submission) return null;
        $reviewAssignment = Repo::reviewAssignment()->getCollector()
            ->filterBySubmissionIds([$submissionId])
            ->filterByReviewerIds([$user->getId()], true)
            ->getMany()
            ->first();
        if (!($reviewAssignment instanceof ReviewAssignment)) return null;
        if ($reviewAssignment->getCancelled() || $reviewAssignment->getDeclined()) return null;

        $contextId = (int)$context->getId();
        $studioUrl = rtrim(trim((string)$this->getSetting($contextId, 'studioUrl')), '/');
        if ($studioUrl === '') return null;
        $secret = $this->getSharedSecret($contextId);
        if ($secret === '') return null;

        $now = time();
        $apiBaseUrl = $request->getDispatcher()->url(
            $request,
            Application::ROUTE_API,
            $context->getPath(),
            'omi-integration'
        );
        $claims = [
            'protocol' => 'omi-integration/1',
            'profile' => 'omi-integration/1/omp',
            'installationId' => $this->getInstallationId($contextId, $request),
            'context' => [
                'externalId' => (string)$contextId,
                'type' => 'press',
                'path' => $context->getPath(),
            ],
            'submission' => ['externalId' => (string)$submissionId],
            'reviewAssignment' => ['externalId' => (string)$reviewAssignment->getId()],
            'actor' => ['externalId' => (string)$user->getId()],
            'actorMode' => 'review',
            'scope' => [
                'review.metadata.read',
                'review.files.read',
                'review.manuscript.read',
                'review.response.write',
                'review.form.read',
                'review.form.write',
            ],
            'iat' => $now,
            'exp' => $now + $this->getTokenTtl($contextId),
            'nonce' => bin2hex(random_bytes(16)),
            'externalBaseUrl' => $request->getBaseUrl(),
            'apiBaseUrl' => $apiBaseUrl,
        ];

        try {
            $token = LaunchToken::issue($claims, $secret);
        } catch (\Throwable) {
            return null;
        }
        return $studioUrl . '/integrations/omp/launch?' . http_build_query($token, '', '&', PHP_QUERY_RFC3986);
    }

    public function getInstallationId(int $contextId, $request): string
    {
        $configured = trim((string)$this->getSetting($contextId, 'installationId'));
        if ($configured !== '') return $configured;
        return 'omp-' . substr(hash('sha256', strtolower(rtrim($request->getBaseUrl(), '/'))), 0, 16);
    }

    public function getSharedSecret(int $contextId): string
    {
        $secret = (string)$this->getSetting($contextId, 'sharedSecret');
        if ($secret !== '') return $secret;
        try {
            $secret = bin2hex(random_bytes(32));
            $this->updateSetting($contextId, 'sharedSecret', $secret, 'string');
            return $secret;
        } catch (\Throwable) {
            return '';
        }
    }

    private function getTokenTtl(int $contextId): int
    {
        $ttl = (int)$this->getSetting($contextId, 'tokenTtl');
        return $ttl >= 60 && $ttl <= 3600 ? $ttl : 300;
    }

    private function isEditorialUser($user, $context): bool
    {
        return $user->hasRole([
            Role::ROLE_ID_MANAGER,
            Role::ROLE_ID_SUB_EDITOR,
            Role::ROLE_ID_ASSISTANT,
        ], $context->getId()) || $user->hasRole([Role::ROLE_ID_SITE_ADMIN], Application::SITE_CONTEXT_ID);
    }

    private function userCanAccessSubmission($user, $context, int $submissionId): bool
    {
        $submission = Repo::submission()->get($submissionId, $context->getId());
        if (!$submission) return false;
        if ($this->isEditorialUser($user, $context)) return true;

        if ($user->hasRole([Role::ROLE_ID_AUTHOR], $context->getId())) {
            $accessibleWorkflowStages = Repo::user()->getAccessibleWorkflowStages(
                $user->getId(),
                $context->getId(),
                $submission
            );
            foreach ($accessibleWorkflowStages as $roles) {
                if (in_array(Role::ROLE_ID_AUTHOR, $roles, true)) return true;
            }
        }

        if ($user->hasRole([Role::ROLE_ID_REVIEWER], $context->getId())) {
            $assignment = Repo::reviewAssignment()->getCollector()
                ->filterBySubmissionIds([$submissionId])
                ->filterByReviewerIds([$user->getId()], true)
                ->getMany()
                ->first();
            return $assignment instanceof ReviewAssignment && !$assignment->getCancelled() && !$assignment->getDeclined();
        }
        return false;
    }
}
