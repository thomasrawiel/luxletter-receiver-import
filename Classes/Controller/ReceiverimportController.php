<?php

declare(strict_types=1);

namespace TRAW\LuxletterReceiverImport\Controller;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use http\Exception\InvalidArgumentException;
use TRAW\LuxletterReceiverImport\Backend\Buttons\NavigationGroupButton;
use Psr\Http\Message\ResponseInterface;
use Shuchkin\SimpleXLSX;
use TRAW\LuxletterReceiverImport\Events\BulkInsertPrepareEvent;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\EventDispatcher\EventDispatcher;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class ReceiverimportController extends ActionController
{
    /**
     * @var ModuleTemplate
     */
    protected ModuleTemplate $moduleTemplate;

    /** @var array<string,int> */
    protected array $groupsCache = []; // key: pid|groupTitle => uid

    /** @var array<string,array{uid:int,usergroup:string,disable:int}> */
    protected array $existingUsersCache = []; // key: email => user record

    /** @var int Batch size for inserts */
    protected int $batchSize = 100;
    /**
     * @var array
     */
    protected array $batchInsert = []; // email => ['email' => ..., 'groups' => [...], 'pid' => ...]

    /**
     * @param ModuleTemplateFactory $moduleTemplateFactory
     * @param ConnectionPool        $connectionPool
     */
    public function __construct(
        protected ModuleTemplateFactory $moduleTemplateFactory,
        protected ConnectionPool        $connectionPool
    )
    {
    }

    /** @phpstan-ignore-next-line */
    public function initializeView($view): void
    {
        $this->view->assignMultiple(['view' => ['controller' => 'receiverImport', 'action' => 'index']]);
    }

    /**
     * @return void
     */
    protected function initializeAction(): void
    {
        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->moduleTemplate->setUiBlock(false);
    }

    /**
     * Main import action for the module.
     *
     * Parses the uploaded XLSX file and imports frontend users.
     * - Validates email and group titles
     * - Updates existing users
     * - Queues new users for batch insert
     * - Tracks success, skipped, and error counts
     *
     * @return ResponseInterface
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    public function indexAction(): ResponseInterface
    {
        $importedCount = 0;
        $queuedForInsertCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;
        $skippedValidCount = 0; // new
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

            $errors = $this->checkArguments($arguments);
            if ($errors === []) {
                $importAttempted = true;
                $importPid = (int)$arguments['importPid'];
                $firstRow = true;
                $hasTitleRow = $arguments['hasTitleRow'] ?? false;
                $titleColumn = ((int)$arguments['titleColumn']) - 1;
                $emailColumn = ((int)$arguments['emailColumn']) - 1;
                $maxTitleLength = (int)($GLOBALS['TCA']['fe_groups']['columns']['title']['config']['max'] ?? 255);

                $this->preloadExistingUsers($importPid);

                if ($xlsx = \Shuchkin\SimpleXLSX::parse($arguments['importFile']['tmp_name'])) {
                    foreach ($xlsx->rows() as $rowNumber => $row) {
                        if ($hasTitleRow && $firstRow) {
                            $firstRow = false;
                            continue;
                        }

                        $title = trim((string)($row[$titleColumn] ?? ''));
                        $email = trim((string)($row[$emailColumn] ?? ''));

                        if (!GeneralUtility::validEmail($email)) {
                            $rowErrors[] = ['row' => $rowNumber + 1, 'email' => $email, 'error' => 'Invalid email format'];
                            $skippedCount++;
                            continue;
                        }

                        $rawGroupTitles = GeneralUtility::trimExplode(',', $title, true);
                        if ($rawGroupTitles === []) {
                            $rowErrors[] = ['row' => $rowNumber + 1, 'email' => $email, 'error' => 'Group title is empty'];
                            $skippedCount++;
                            continue;
                        }

                        $groupUids = [];
                        foreach ($rawGroupTitles as $singleTitle) {
                            if (mb_strlen($singleTitle) > $maxTitleLength) {
                                $rowErrors[] = [
                                    'row' => $rowNumber + 1,
                                    'email' => $email,
                                    'error' => 'Group title "' . $singleTitle . '" exceeds max length of ' . $maxTitleLength,
                                ];
                                $skippedCount++;
                                continue 2; // skip entire row
                            }
                            $groupUids[] = $this->getCachedGroupUid($singleTitle, $importPid);
                        }
                        $groupUids = array_unique($groupUids);

                        // Existing user: update individually
                        if (isset($this->existingUsersCache[$email])) {
                            $status = $this->subscribeFrontendUser($groupUids, $importPid, $email);
                            match ($status) {
                                'update' => $updatedCount++,
                                'skip' => $skippedValidCount++, // <-- instead of incrementing $skippedCount
                            };
                            continue;
                        }

                        // Batch new user
                        if (isset($this->batchInsert[$email])) {
                            $this->batchInsert[$email]['groups'] = array_unique(array_merge(
                                $this->batchInsert[$email]['groups'],
                                $groupUids
                            ));
                        } else {
                            $this->batchInsert[$email] = [
                                'pid' => $importPid,
                                'groups' => $groupUids,
                            ];
                        }

                        if (count($this->batchInsert) >= $this->batchSize) {
                            $queuedForInsertCount = count($this->batchInsert);
                            $this->flushBatch();
                            $importedCount += $queuedForInsertCount;
                        }
                    }

                    // Flush remaining batch
                    $remaining = count($this->batchInsert);
                    if ($remaining > 0) {
                        $this->flushBatch();
                        $importedCount += $remaining;
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

        $this->moduleTemplate->assignMultiple([
            'importAttempted' => $importAttempted,
            'importSuccess' => $this->fetchSuccessState($importedCount, $updatedCount, $skippedCount, count($errors) + count($rowErrors)),
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

    /**
     * Determines the TYPO3 InfoboxViewHelper state for import result.
     *
     * Returns one of:
     * - STATE_OK (0) – successful import/update
     * - STATE_WARNING (1) – skipped rows or partial errors
     * - STATE_ERROR (2) – errors with no successful imports
     * - STATE_INFO (-1) – nothing happened
     *
     * @param int $importedCount Number of successfully imported new users
     * @param int $updatedCount  Number of successfully updated existing users
     * @param int $skippedCount  Number of skipped rows
     * @param int $errorCount    Number of errors
     *
     * @return int InfoboxViewHelper state constant
     */
    private function fetchSuccessState(int $importedCount, int $updatedCount, int $skippedCount, int $errorCount): int
    {
        $successCount = $importedCount + $updatedCount;

        if ($errorCount > 0) {
            return $successCount > 0 ? \TYPO3\CMS\Fluid\ViewHelpers\Be\InfoboxViewHelper::STATE_WARNING
                : \TYPO3\CMS\Fluid\ViewHelpers\Be\InfoboxViewHelper::STATE_ERROR;
        }

        if ($skippedCount > 0) {
            return \TYPO3\CMS\Fluid\ViewHelpers\Be\InfoboxViewHelper::STATE_WARNING;
        }

        if ($successCount > 0) {
            return \TYPO3\CMS\Fluid\ViewHelpers\Be\InfoboxViewHelper::STATE_OK;
        }

        return \TYPO3\CMS\Fluid\ViewHelpers\Be\InfoboxViewHelper::STATE_INFO;
    }

    /**
     * Validates required import arguments.
     *
     * @param array $arguments Module form input arguments
     *
     * @return array<string,string> Array of errors keyed by argument name
     */
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

    /**
     * Adds a navigation group button to the module button bar.
     *
     * @param array<string,mixed> $configuration
     */
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

    /**
     * Adds a shortcut button for this module to the TYPO3 backend.
     */
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
     * Subscribe or update a frontend user.
     *
     * - If user exists, merge FE groups and update only if groups changed.
     * - New users are queued for batch insert (handled elsewhere)
     *
     * @param array<int> $feGroupsUids Array of group UIDs to assign
     * @param int        $importPid    Page ID where user belongs
     * @param string     $email        Email of the frontend user
     *
     * @return string 'insert' | 'update' | 'skip'
     */
    protected function subscribeFrontendUser(array $feGroupsUids, int $importPid, string $email): string
    {
        $connectionFeUsers = $this->connectionPool->getConnectionForTable('fe_users');

        $feUser = $this->existingUsersCache[$email] ?? null;

        // Existing user: update only if new groups
        if ($feUser !== null && ($feUser['uid'] ?? 0) > 0) {
            $existingGroups = array_filter(
                array_map('intval', explode(',', (string)$feUser['usergroup'])),
                fn($v) => $v > 0
            );

            $mergedGroups = array_unique(array_merge($existingGroups, $feGroupsUids));

            if ($mergedGroups !== $existingGroups) {
                $connectionFeUsers->update(
                    'fe_users',
                    [
                        'usergroup' => implode(',', $mergedGroups),
                        'tstamp' => $this->getSimAccessTime(),
                    ],
                    ['uid' => $feUser['uid']]
                );

                $this->existingUsersCache[$email]['usergroup'] = implode(',', $mergedGroups);

                return 'update';
            }

            return 'skip';
        }

        // New users are handled via batch insert, so this is actually unreachable. i keep it for documentation purposes
        return 'insert';
    }

    /**
     * Executes batch insert for new users in $this->batchInsert.
     *
     * - Generates passwords for new users
     * - Fires BulkInsertPrepareEvent for extensibility
     * - Validates column/value/type count
     * - Updates $this->existingUsersCache with inserted users
     *
     * @throws \InvalidArgumentException If columns/types/values mismatch
     */
    protected function flushBatch(): void
    {
        if (empty($this->batchInsert)) {
            return;
        }

        $newUsers = [];
        $hashInstance = GeneralUtility::makeInstance(PasswordHashFactory::class)
            ->getDefaultHashInstance('FE');

        foreach ($this->batchInsert as $email => $userData) {
            if (!isset($this->existingUsersCache[$email])) {
                // New user → prepare for bulk insert
                $hashedPassword = $hashInstance->getHashedPassword(
                    substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!?=§%-'), 0, 16)
                );

                $newUsers[] = [
                    'username' => $email,
                    'email' => $email,
                    'password' => $hashedPassword,
                    'usergroup' => implode(',', $userData['groups']),
                    'pid' => $userData['pid'],
                    'tstamp' => $this->getSimAccessTime(),
                    'crdate' => $this->getSimAccessTime(),
                    'tx_receiver_imported' => 1,
                ];
            }
        }
        $connectionFeUsers = $this->connectionPool->getConnectionForTable('fe_users');
        if ($newUsers !== []) {
            // Dispatch an event so listeners can modify columns/values/types
            $event = $this->eventDispatcher->dispatch(
                new BulkInsertPrepareEvent(
                    'fe_users',
                    ['pid', 'username', 'email', 'password', 'usergroup', 'tstamp', 'crdate', 'tx_receiver_imported'],
                    array_map(
                        fn(array $row) => [
                            $row['pid'],
                            $row['username'],
                            $row['email'],
                            $row['password'],
                            $row['usergroup'],
                            $row['tstamp'],
                            $row['crdate'],
                            $row['tx_receiver_imported'],
                        ],
                        $newUsers
                    ),
                    [
                        Connection::PARAM_INT,
                        Connection::PARAM_STR,
                        Connection::PARAM_STR,
                        Connection::PARAM_STR,
                        Connection::PARAM_STR,
                        Connection::PARAM_INT,
                        Connection::PARAM_INT,
                        Connection::PARAM_INT,
                    ]
                )
            );

            // Use possibly modified data
            $columns = $event->getColumns();
            $values = $event->getValues();
            $types = $event->getTypes();


            $columnCount = count($columns);
            $typesCount = count($types);

            if ($columnCount !== $typesCount) {
                throw new \InvalidArgumentException('Column count (' . $columnCount . ') does not match number of types (' . $typesCount . ')');
            }
            foreach ($values as $i => $row) {
                $rowCount = count($row);
                if ($rowCount !== $columnCount) {
                    throw new \InvalidArgumentException(
                        "Row $i has $rowCount values but there are $columnCount columns"
                    );
                }
            }
            // Execute bulk insert
            $connectionFeUsers->bulkInsert('fe_users', $values, $columns, $types);

            // Update cache with inserted users
            $qb = $this->connectionPool->getQueryBuilderForTable('fe_users');
            $emails = array_column($newUsers, 'email');
            $rows = $qb
                ->select('uid', 'email', 'usergroup', 'disable')
                ->from('fe_users')
                ->where($qb->expr()->in('email', $qb->createNamedParameter($emails, ArrayParameterType::STRING)))
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($rows as $row) {
                $this->existingUsersCache[$row['email']] = [
                    'uid' => (int)$row['uid'],
                    'usergroup' => (string)$row['usergroup'],
                    'disable' => (int)($row['disable'] ?? 0),
                ];
            }
        }

        $this->batchInsert = [];
    }


    /**
     * Returns the UID of a frontend user group.
     *
     * - Creates the group if it does not exist
     *
     * @param string $frontendUserGroupTitle
     * @param int    $importPid
     *
     * @return int UID of the group
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
     * Preloads existing frontend users into $this->existingUsersCache.
     *
     * @param int $importPid
     * @SuppressWarnings(PHPMD.Superglobals)
     */
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

    /**
     * Returns cached group UID, creating the group if necessary.
     *
     * @param string $frontendUserGroupTitle
     * @param int    $importPid
     *
     * @return int Group UID
     */
    protected function getCachedGroupUid(string $frontendUserGroupTitle, int $importPid): int
    {
        $key = $importPid . '|' . $frontendUserGroupTitle;
        if (!isset($this->groupsCache[$key])) {
            $this->groupsCache[$key] = $this->getGroupsUid($frontendUserGroupTitle, $importPid);
        }
        return $this->groupsCache[$key];
    }


    /**
     *
     *  Returns the current simulated access time.
     *
     * @return int UNIX timestamp
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    protected function getSimAccessTime(): int
    {
        return (int)$GLOBALS['SIM_ACCESS_TIME'];
    }
}
