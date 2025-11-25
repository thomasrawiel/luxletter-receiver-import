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
    protected ModuleTemplate $moduleTemplate;

    /** @var array<string,int> */
    protected array $groupsCache = []; // key: pid|groupTitle => uid

    /** @var array<string,array{uid:int,usergroup:string,disable:int}> */
    protected array $existingUsersCache = []; // key: email => user record

    /** @var int Batch size for inserts */
    protected int $batchSize = 100;
    protected array $batchInsert = []; // email => ['email' => ..., 'groups' => [...], 'pid' => ...]

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

    protected function initializeAction(): void
    {
        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->moduleTemplate->setUiBlock(false);
    }

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
                                'skip'   => $skippedValidCount++, // <-- instead of incrementing $skippedCount
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


    /**
     * Flushes queued batch insert/update.
     *
     * @param array<string,array{email:string,pid:int,groups:array<int>,row:int}> $batchInsert
     */
    protected function flushBatchInsert(array $batchInsert, int &$importedCount, int &$updatedCount, array &$rowErrors): int
    {
        $connectionFeUsers = $this->connectionPool->getConnectionForTable('fe_users');
        $insertedThisBatch = 0;

        foreach ($batchInsert as $email => $data) {
            $status = $this->subscribeFrontendUser($data['groups'], $data['pid'], $email);
            if ($status === 'insert') {
                $insertedThisBatch++;
                $importedCount++;
            } elseif ($status === 'update') {
                $updatedCount++;
            }
        }

        return $insertedThisBatch;
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
     * Subscribe a frontend user.
     *
     * - New users are queued for batch insert.
     * - Existing users are updated immediately if new groups are added.
     *
     * @param array<int> $feGroupsUids UIDs of groups that should be assigned
     * @param int        $importPid
     * @param string     $email
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
