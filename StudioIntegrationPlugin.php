<?php
namespace APP\plugins\generic\studioIntegration;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\studioIntegration\classes\Core\LaunchToken;
use APP\plugins\generic\studioIntegration\classes\StudioIntegrationSettingsForm;
use PKP\core\APIRouter;
use PKP\core\JSONMessage;
use PKP\core\PKPApplication;
use PKP\db\DAORegistry;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;
use PKP\submission\reviewAssignment\ReviewAssignment;

class StudioIntegrationPlugin extends GenericPlugin
{
    private bool $assetsInjected = false;

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('TemplateManager::display', $this->displayTemplateHook(...));
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
        require_once($this->getPluginPath() . '/StudioIntegrationNativeApiController.php');
        $apiRouter->registerPluginApiControllers([
            new StudioIntegrationApiController($this),
            new StudioIntegrationNativeApiController($this),
        ]);
        return Hook::CONTINUE;
    }

    public function displayTemplateHook(string $hookName, array $params): bool
    {
        if ($this->assetsInjected) {
            return false;
        }

        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $user = $request->getUser();
        if (!$context || !$user) {
            return false;
        }

        $requestedPage = $request->getRequestedPage();
        if (!in_array($requestedPage, ['workflow', 'dashboard', 'reviewer'], true)) {
            return false;
        }

        $launchMode = $this->resolveLaunchMode(
            $request,
            $requestedPage === 'reviewer' ? 'review' : 'auto'
        );
        $contextId = (int)$context->getId();
        $studioUrl = rtrim(trim((string)$this->getSetting($contextId, 'studioUrl')), '/');
        if ($studioUrl === '') {
            return false;
        }
        if ($this->getSharedSecret($contextId) === '') {
            return false;
        }

        $templateMgr = $params[0];
        $pluginBase = $request->getBaseUrl() . '/' . $this->getPluginPath();
        $launchEndpoint = $request->url(
            $context->getPath(),
            'omiIntegration',
            'launch'
        );

        $config = json_encode([
            'launchEndpoint' => $launchEndpoint,
            'mode' => $launchMode,
            'label' => __('plugins.generic.studioIntegration.openInStudio'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        if ($config === false) {
            return false;
        }

        $templateMgr->addHeader(
            'studioIntegrationConfig',
            '<script>window.OMI_STUDIO_INTEGRATION=' . $config . ';</script>',
            ['contexts' => ['backend']]
        );
        $templateMgr->addJavaScript(
            'studioIntegrationLauncher',
            $pluginBase . '/js/studioIntegration.js',
            ['contexts' => ['backend']]
        );
        $templateMgr->addStyleSheet(
            'studioIntegrationLauncher',
            $pluginBase . '/css/studioIntegration.css',
            ['contexts' => ['backend']]
        );

        $this->assetsInjected = true;
        return false;
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
                'author.revision.write',
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
            ->filterByContextIds([$context->getId()])
            ->filterBySubmissionIds([$submissionId])
            ->filterByReviewerIds([$user->getId()])
            ->filterByIsIncomplete(true)
            ->limit(1)
            ->getMany()
            ->first();
        if (!($reviewAssignment instanceof ReviewAssignment)) return null;
        $component = $this->reviewComponentForAssignment($submission, $reviewAssignment);
        if ($component === null) return null;

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
            'component' => $component,
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
                'review.revision.write',
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

    private function reviewComponentForAssignment(object $submission, ReviewAssignment $assignment): ?array
    {
        /** @var \PKP\submission\ReviewFilesDAO $reviewFilesDao */
        $reviewFilesDao = DAORegistry::getDAO('ReviewFilesDAO');
        $chapterIds = [];
        $files = Repo::submissionFile()->getCollector()
            ->filterBySubmissionIds([(int)$submission->getId()])
            ->getMany();
        foreach ($files as $file) {
            if (!$reviewFilesDao->check((int)$assignment->getId(), (int)$file->getId())) continue;
            $chapterId = (int)$file->getData('chapterId');
            if ($chapterId > 0) $chapterIds[$chapterId] = true;
        }
        if (count($chapterIds) !== 1) return null;

        $chapterId = (int)array_key_first($chapterIds);
        $publication = $submission->getCurrentPublication();
        if (!$publication) return null;
        /** @var \APP\monograph\ChapterDAO $chapterDao */
        $chapterDao = DAORegistry::getDAO('ChapterDAO');
        $chapter = $chapterDao->getChapter($chapterId, (int)$publication->getId());
        if (!$chapter) return null;

        return [
            'externalId' => (string)$chapterId,
            'type' => 'article',
            'title' => (string)$chapter->getTitle((string)$submission->getData('locale')),
        ];
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
                ->filterByContextIds([$context->getId()])
                ->filterBySubmissionIds([$submissionId])
                ->filterByReviewerIds([$user->getId()])
                ->filterByIsAccessibleByReviewer(true)
                ->limit(1)
                ->getMany()
                ->first();
            return $assignment instanceof ReviewAssignment;
        }
        return false;
    }
}
