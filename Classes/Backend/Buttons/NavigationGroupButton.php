<?php

declare(strict_types=1);

namespace TRAW\LuxletterReceiverImport\Backend\Buttons;

use TYPO3\CMS\Backend\Template\Components\Buttons\AbstractButton;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;

class NavigationGroupButton extends AbstractButton
{
    protected UriBuilder $uriBuilder;
    protected IconFactory $iconFactory;

    public function __construct(
        protected Request $request,
        protected string $currentAction,
        protected array $configuration
    ) {
        $this->uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        $this->uriBuilder->setRequest($this->request);

        $this->iconFactory = GeneralUtility::makeInstance(IconFactory::class);
    }

    public function render(): string
    {
        $content = $this->iconFactory->getIcon('extension-lux')->render();
        $content .= '<div class="btn-group" role="group">';
        foreach ($this->configuration as $action => $label) {
            $url = $this->uriBuilder->uriFor($action);
            $class = $this->currentAction === $action ? 'btn-primary' : 'btn-default';
            $content .= '<a href="' . $url . '" class="btn ' . $class . '">' . $label . '</a>';
        }
        $content .= '</div>';
        return $content . '<div style="padding-top: 5px;">Receiver Import</a></div>';
    }

    public function __toString(): string
    {
        return $this->render();
    }

    public function isValid(): true
    {
        return true;
    }
}
