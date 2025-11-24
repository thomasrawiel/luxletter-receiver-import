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

    /** @var array<string,int> */
    protected array $groupsCache = []; // key: pid|groupTitle => uid

    /** @var array<string,array{uid:int,usergroup:string,disable:int}> */
    protected array $existingUsersCache = []; // key: email => user record

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
        $importedCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;
        $rowErrors = [];
        $importAttempted = false;

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

            // Check form arguments
            $errors = $this->checkArguments($arguments);
            if ($errors === []) {
                $importAttempted = true;
                $importPid = (int)$arguments['importPid'];
                $firstRow = true;
                $hasTitleRow = $arguments['hasTitleRow'] ?? false;
                $titleColumn = ((int)$arguments['titleColumn']) - 1;
                $emailColumn = ((int)$arguments['emailColumn']) - 1;

                $maxTitleLength = (int)($GLOBALS['TCA']['fe_groups']['columns']['title']['config']['max'] ?? 255);

                // Preload existing users for this PID
                $this->preloadExistingUsers($importPid);

                if ($xlsx = \Shuchkin\SimpleXLSX::parse($arguments['importFile']['tmp_name'])) {
                    foreach ($xlsx->rows() as $rowNumber => $row) {
                        if ($hasTitleRow && $firstRow) {
                            $firstRow = false;
                            continue;
                        }

                        $title = trim((string)($row[$titleColumn] ?? ''));
                        $email = trim((string)($row[$emailColumn] ?? ''));

                        // --- Validate email ---
                        if (!GeneralUtility::validEmail($email)) {
                            $rowErrors[] = [
                                'row' => $rowNumber + 1,
                                'email' => $email,
                                'error' => 'Invalid email format',
                            ];
                            $skippedCount++;
                            continue;
                        }

                        // --- Validate group title ---
                        if ($title === '') {
                            $rowErrors[] = [
                                'row' => $rowNumber + 1,
                                'email' => $email,
                                'error' => 'Group title is empty',
                            ];
                            $skippedCount++;
                            continue;
                        }

                        if (mb_strlen($title) > $maxTitleLength) {
                            $rowErrors[] = [
                                'row' => $rowNumber + 1,
                                'email' => $email,
                                'error' => 'Group title exceeds max length of ' . $maxTitleLength,
                            ];
                            $skippedCount++;
                            continue;
                        }

                        $feGroupsId = $this->getCachedGroupUid($title, $importPid);

                        // --- Subscribe user and get status ---
                        $status = $this->subscribeFrontendUser($feGroupsId, $importPid, $email);

                        match ($status) {
                            'insert' => $importedCount++,
                            'update' => $updatedCount++,
                            default => $skippedCount++,
                        };
                    }
                } else {
                    $rowErrors[] = [
                        'row' => 0,
                        'email' => '',
                        'error' => 'Failed to parse XLSX: ' . \Shuchkin\SimpleXLSX::parseError(),
                    ];
                }
            }
            $this->moduleTemplate->assign('data', $arguments);
        } else {
            $errors = [];
        }

        // Assign template variables
        $this->moduleTemplate->assignMultiple([
            'importAttempted' => $importAttempted,
            'importSuccess' => ($importedCount + $updatedCount) > 0,
            'importedCount' => $importedCount,
            'updatedCount' => $updatedCount,
            'skippedCount' => $skippedCount,
            'rowErrors' => $rowErrors,
            'errors' => $errors,
        ]);

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
    protected function subscribeFrontendUser(int $feGroupsUid, int $importPid, string $email): string
    {
        $connectionFeUsers = $this->connectionPool->getConnectionForTable('fe_users');

        $feUser = $this->existingUsersCache[$email] ?? null;

        if ($feUser !== null && ($feUser['uid'] ?? 0) > 0) {
            // Update usergroup if necessary
            $groups = array_filter(
                array_map('intval', explode(',', (string)$feUser['usergroup'])),
                fn($v) => $v > 0
            );
            if (!in_array($feGroupsUid, $groups, true)) {
                $groups[] = $feGroupsUid;

                $connectionFeUsers->update(
                    'fe_users',
                    [
                        'usergroup' => implode(',', $groups),
                        'tstamp' => $this->getSimAccessTime(),
                    ],
                    ['uid' => $feUser['uid']]
                );

                // Update cache to reflect change
                $this->existingUsersCache[$email]['usergroup'] = implode(',', $groups);
                return 'update';
            }
            return 'skip';
        }
        // New user
        $hashInstance = GeneralUtility::makeInstance(PasswordHashFactory::class)->getDefaultHashInstance('FE');
        $hashedPassword = $hashInstance->getHashedPassword(
            substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!?=§%-'), 0, 16)
        );

        $connectionFeUsers->insert(
            'fe_users',
            [
                'username' => $email,
                'email' => $email,
                'password' => $hashedPassword,
                'usergroup' => (string)$feGroupsUid,
                'pid' => $importPid,
                'tstamp' => $this->getSimAccessTime(),
                'crdate' => $this->getSimAccessTime(),
                'tx_receiver_imported' => 1,
            ]
        );

        // Add new user to cache
        $this->existingUsersCache[$email] = [
            'uid' => (int)$connectionFeUsers->lastInsertId('fe_users'),
            'usergroup' => (string)$feGroupsUid,
            'disable' => 0,
        ];

        return 'insert';

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

    protected function preloadExistingUsers(int $importPid): void
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('fe_users');
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $rows = $qb
            ->select('uid', 'email', 'usergroup', $GLOBALS['TCA']['fe_users']['ctrl']['enablecolumns']['disabled'] ?? 'disable')
            ->from('fe_users')
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter($importPid, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $this->existingUsersCache = array_column($rows, null, 'email');
    }

    protected function getCachedGroupUid(string $frontendUserGroupTitle, int $importPid): int
    {
        $key = $importPid . '|' . $frontendUserGroupTitle;
        if (!isset($this->groupsCache[$key])) {
            $this->groupsCache[$key] = $this->getGroupsUid($frontendUserGroupTitle, $importPid);
        }
        return $this->groupsCache[$key];
    }


    /**
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    protected function getSimAccessTime(): int
    {
        return (int)$GLOBALS['SIM_ACCESS_TIME'];
    }
}
