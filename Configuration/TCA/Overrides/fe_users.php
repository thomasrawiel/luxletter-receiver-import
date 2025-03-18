<?php

defined('TYPO3') || die('Access denied');

(static function (): void {

    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('fe_users', [
        'tx_receiver_imported' => [
            'label' => 'LLL:EXT:luxletter_receiver_import/Resources/Private/Language/locallang_db.xlf:fe_users.tx_receiver_imported',
            'config' => [
                'type' => 'check',
            ],
        ],
    ]);
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
        'fe_users',
        'tx_receiver_imported',
        '',
        ''
    );
})();
