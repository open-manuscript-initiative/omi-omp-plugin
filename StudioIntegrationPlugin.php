<?php
namespace APP\plugins\generic\studioIntegration;

use APP\core\Application;
use APP\plugins\generic\studioIntegration\classes\Core\LaunchToken;
use PKP\core\JSONMessage;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class StudioIntegrationPlugin extends GenericPlugin
{
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('LoadHandler', $this->loadApiHandler(...));
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
        require_once($this->getPluginPath() . '/classes/StudioIntegrationSettingsForm.php');
        $context = $request->getContext();
        $contextId = $context ? $context->getId() : PKPApplication::SITE_CONTEXT_ID;
        $form = new classes\StudioIntegrationSettingsForm($this, $contextId);
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
        if ($page !== 'omiIntegration') {
            return false;
        }
        require_once($this->getPluginPath() . '/StudioIntegrationApiHandler.php');
        $handler = new StudioIntegrationApiHandler($this);
        return true;
    }

    public function getInstallationId(int $contextId, $request): string
    {
        $configured = trim((string)$this->getSetting($contextId, 'installationId'));
        if ($configured !== '') {
            return $configured;
        }
        return 'omp-' . substr(hash('sha256', strtolower(rtrim($request->getBaseUrl(), '/'))), 0, 16);
    }

    public function getSharedSecret(int $contextId): string
    {
        $secret = (string)$this->getSetting($contextId, 'sharedSecret');
        if ($secret !== '') {
            return $secret;
        }
        try {
            $secret = bin2hex(random_bytes(32));
            $this->updateSetting($contextId, 'sharedSecret', $secret, 'string');
            return $secret;
        } catch (\Throwable) {
            return '';
        }
    }

    public function issueLaunch(array $claims, int $contextId): array
    {
        return LaunchToken::issue($claims, $this->getSharedSecret($contextId));
    }
}
