<?php

declare(strict_types=1);

namespace TRAW\LuxletterReceiverImport\Controller;

use Doctrine\DBAL\Exception;
use TRAW\LuxletterReceiverImport\Backend\Buttons\NavigationGroupButton;
use Psr\Http\Message\ResponseInterface;
use Shuchkin\SimpleXLSX;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class ReceiverimportController extends ActionController
{
    protected ModuleTemplate $moduleTemplate;

    public function __construct(
        protected ModuleTemplateFactory $moduleTemplateFactory,
        protected IconFactory $iconFactory
    ) {
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
                    $hashInstance = GeneralUtility::makeInstance(PasswordHashFactory::class)->getDefaultHashInstance('FE');
                    $hashedPassword = $hashInstance->getHashedPassword(substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!?=§%-'), 0, 16));

                    foreach ($xlsx->rows() as $row) {
                        if ($hasTitleRow && $firstRow) {
                            $firstRow = false;
                            continue;
                        }
                        $title = (string)$row[$titleColumn];
                        $email = (string)$row[$emailColumn];

                        // Check if a record is existing and subscribed
                        if (!$this->checkIfFrontendUserIsExisting($email, $importPid)) {
                            $this->subscribeFrontendUser($title, $importPid, $email, $hashedPassword);
                        }
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
    protected function subscribeFrontendUser(string $frontendUserGroupTitle, int $importPid, string $email, ?string $hashedPassword): void
    {
        // Get Group UID
        $feGroupsUid = $this->getGroupsUid($frontendUserGroupTitle, $importPid);

        $connectionFeUsers = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('fe_users');

        $feUser = $connectionFeUsers
            ->select(
                ['uid', 'usergroup'],
                'fe_users',
                [
                    'email' => $email,
                    'pid' => $importPid,
                    'deleted' => 0,
                ],
                [],
                [],
                1
            )->fetchAssociative();

        if ($feUser && $feUser['uid'] > 0) {
            // Update User
            $groups = GeneralUtility::intExplode(',', (string)$feUser['usergroup']);
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
     * @return bool
     * @throws Exception
     */
    protected function checkIfFrontendUserIsExisting(string $email, int $importPid): bool
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('fe_users');
        $queryBuilder
            ->select('u.uid')
            ->from('fe_users', 'u')
            ->join(
                'u',
                'fe_groups',
                'g',
                $queryBuilder->expr()->eq('u.usergroup', $queryBuilder->quoteIdentifier('g.uid'))
            )
            ->where($queryBuilder->expr()->eq('u.email', $queryBuilder->createNamedParameter($email)))
            ->andWhere($queryBuilder->expr()->eq('u.pid', $importPid))
            ->andWhere($queryBuilder->expr()->eq('u.deleted', 0))
            ->setMaxResults(1);
        return ((int)$queryBuilder->executeQuery()->fetchOne()) > 0;
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
