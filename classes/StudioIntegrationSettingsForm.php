<?php
namespace APP\plugins\generic\studioIntegration\classes;

use PKP\form\Form;

class StudioIntegrationSettingsForm extends Form
{
    public function __construct(private $plugin, private int $contextId)
    {
        parent::__construct($plugin->getTemplateResource('settings.tpl'));
        $this->addCheck(new \PKP\form\validation\FormValidatorPost($this));
        $this->addCheck(new \PKP\form\validation\FormValidatorCSRF($this));
    }

    public function initData()
    {
        $this->_data = [
            'studioUrl' => $this->plugin->getSetting($this->contextId, 'studioUrl'),
            'installationId' => $this->plugin->getSetting($this->contextId, 'installationId'),
            'sharedSecret' => $this->plugin->getSetting($this->contextId, 'sharedSecret'),
            'tokenTtl' => $this->plugin->getSetting($this->contextId, 'tokenTtl') ?: 300,
        ];
    }

    public function readInputData()
    {
        $this->readUserVars(['studioUrl', 'installationId', 'sharedSecret', 'tokenTtl']);
    }

    public function execute(...$functionArgs)
    {
        foreach (['studioUrl', 'installationId', 'sharedSecret'] as $name) {
            $this->plugin->updateSetting($this->contextId, $name, trim((string)$this->getData($name)), 'string');
        }
        $ttl = max(60, min(3600, (int)$this->getData('tokenTtl')));
        $this->plugin->updateSetting($this->contextId, 'tokenTtl', $ttl, 'int');
        parent::execute(...$functionArgs);
    }
}
