<?php

declare(strict_types=1);

namespace TRAW\LuxletterReceiverImport\Controller;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use TRAW\LuxletterReceiverImport\Backend\Buttons\NavigationGroupButton;
use Psr\Http\Message\ResponseInterface;
use Shuchkin\SimpleXLSX;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class ReceiverimportController extends ActionController
{
    protected ModuleTemplate $moduleTemplate;

    public function __construct(
        protected ModuleTemplateFactory $moduleTemplateFactory,
        protected IconFactory           $iconFactory,
        protected ConnectionPool        $connectionPool,
    )
    {
    }

    /** @phpstan-ignore-next-line */
    public function initializeView($view): void
    {
        $this->view->assignMultiple(['view' => ['controller' => 'receiverImport', 'action' => 'index']]);
    }

    protected function initializeAction(): void
    {
        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);
    }

    public function indexAction(): ResponseInterface
    {
        $errors = [];
        $arguments = $this->request->getArguments();
        if (array_key_exists('tmp_name', $arguments)) {
            $importFile = [
                'name' => $arguments['name'],
                'type' => $arguments['type'],
                'size' => (int)$arguments['size'],
                'tmp_name' => $arguments['tmp_name'],
                'error' => $arguments['error'],
            ];
            unset($arguments['name'], $arguments['type'], $arguments['size'], $arguments['tmp_name'], $arguments['error']);
            $arguments['importFile'] = $importFile;

            $errors = $this->checkArguments($arguments);
            if ($errors === []) {
                if ($xlsx = SimpleXLSX::parse($arguments['importFile']['tmp_name'])) {
                    $firstRow = true;
                    $hasTitleRow = $arguments['hasTitleRow'] ?? false;
                    $titleColumn = ((int)$arguments['titleColumn']) - 1;
                    $emailColumn = ((int)$arguments['emailColumn']) - 1;
                    $importPid = (int)$arguments['importPid'];

                    foreach ($xlsx->rows() as $row) {
                        if ($hasTitleRow && $firstRow) {
                            $firstRow = false;
                            continue;
                        }
                        $title = (string)$row[$titleColumn];
                        $email = (string)$row[$emailColumn];

                        $this->subscribeFrontendUser($title, $importPid, $email);
                        $this->moduleTemplate->assign('importSuccess', true);
                    }
                } else {
                    $errors['importFile'] = SimpleXLSX::parseError();
                }
            }
            $this->moduleTemplate->assign('data', $this->request->getArguments());
        }
        $this->moduleTemplate->assign('errors', $errors);

        $this->addNavigationButtons(['index' => 'Import']);
        $this->addShortcutButton();

        return $this->moduleTemplate->renderResponse('Index');
    }

    private function checkArguments(array $arguments): array
    {
        $errors = [];

        if ($arguments['importFile']['size'] === 0) {
            $errors['importFile'] = 'Please upload a file';
        }

        if (empty($arguments['titleColumn']) || (int)$arguments['titleColumn'] <= 0) {
            $errors['titleColumn'] = 'Argument titleColumn is missing or it is no integer';
        }

        if (empty($arguments['emailColumn']) || (int)$arguments['emailColumn'] <= 0) {
            $errors['emailColumn'] = 'Argument emailColumn is missing or it is no integer';
        }

        if (empty($arguments['importPid']) || (int)$arguments['importPid'] <= 0) {
            $errors['importPid'] = 'Argument importPid ist missing or it is no integer';
        }

        return $errors;
    }

    protected function addNavigationButtons(array $configuration): void
    {
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $navigationGroupButton = GeneralUtility::makeInstance(
            NavigationGroupButton::class,
            $this->request,
            'index',
            $configuration,
        );
        $buttonBar->addButton($navigationGroupButton, ButtonBar::BUTTON_POSITION_LEFT, 2);
    }

    protected function addShortcutButton(): void
    {
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $shortCutButton = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar()->makeShortcutButton();
        $shortCutButton
            ->setRouteIdentifier('lux_LuxletterReceiverImport')
            ->setDisplayName('Shortcut')
            ->setArguments(['action' => 'index', 'controller' => 'receiverImport']);
        $buttonBar->addButton($shortCutButton, ButtonBar::BUTTON_POSITION_RIGHT, 1);
    }

    /**
     * @return void
     */
    protected function subscribeFrontendUser(string $frontendUserGroupTitle, int $importPid, string $email): void
    {
        // Get Group UID
        $feGroupsUid = $this->getGroupsUid($frontendUserGroupTitle, $importPid);
        if ($feGroupsUid <= 0) {
            return;
        }

        $qb = $this->connectionPool
            ->getQueryBuilderForTable('fe_users');
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $constraints = [
            $qb->expr()->eq('email', $qb->createNamedParameter($email, ParameterType::STRING)),
            $qb->expr()->eq('pid', $qb->createNamedParameter($importPid, ParameterType::INTEGER)),
        ];

        $feUser = $qb
            ->select('uid', 'usergroup', $GLOBALS['TCA']['fe_users']['ctrl']['enablecolumns']['disabled'] ?? 'disable')
            ->from('fe_users')
            ->where(...$constraints)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        $connectionFeUsers = $this->connectionPool->getConnectionForTable('fe_users');
        if ($feUser !== false && ($feUser['uid'] ?? 0) > 0) {
            // Update User
            $groups = GeneralUtility::intExplode(',', (string)$feUser['usergroup'], true);
            if (!in_array($feGroupsUid, $groups, true)) {
                $groups[] = $feGroupsUid;
                $connectionFeUsers
                    ->update(
                        'fe_users',
                        [
                            'usergroup' => implode(',', $groups),
                            'tstamp' => $this->getSimAccessTime(),
                        ],
                        [
                            'uid' => $feUser['uid'],
                        ]
                    );
            }
        } else {
            $hashInstance = GeneralUtility::makeInstance(PasswordHashFactory::class)->getDefaultHashInstance('FE');
            $hashedPassword = $hashInstance->getHashedPassword(substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!?=§%-'), 0, 16));

            $connectionFeUsers
                ->insert(
                    'fe_users',
                    [
                        'username' => $email,
                        'email' => $email,
                        'password' => $hashedPassword,
                        'usergroup' => $feGroupsUid,
                        'pid' => $importPid,
                        'tstamp' => $this->getSimAccessTime(),
                        'crdate' => $this->getSimAccessTime(),
                        'tx_receiver_imported' => 1,
                    ]
                );
        }
    }

    /**
     * @return int
     */
    protected function getGroupsUid(string $frontendUserGroupTitle, int $importPid): int
    {
        $connectionFeGroups = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('fe_groups');
        $feGroupsUid = $connectionFeGroups
            ->select(
                ['uid'],
                'fe_groups',
                [
                    'title' => $frontendUserGroupTitle,
                    'pid' => $importPid,
                    'deleted' => 0,
                ],
                [],
                [],
                1
            )
            ->fetchOne();

        if ($feGroupsUid === false) {
            // Create new Group
            $connectionFeGroups
                ->insert(
                    'fe_groups',
                    [
                        'title' => $frontendUserGroupTitle,
                        'pid' => $importPid,
                        'luxletter_receiver' => 1,
                        'tstamp' => $this->getSimAccessTime(),
                        'crdate' => $this->getSimAccessTime(),
                        'tx_receiver_imported' => 1,
                    ]
                );
            $feGroupsUid = (int)$connectionFeGroups->lastInsertId('fe_groups');
        }
        return $feGroupsUid;
    }

    /**
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    protected function getSimAccessTime(): int
    {
        return (int)$GLOBALS['SIM_ACCESS_TIME'];
    }
}
