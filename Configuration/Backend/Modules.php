<?php

return [
    'lux_LuxletterReceiverImport' => [
        'parent' => 'lux_module',
        'access' => 'user',
        'iconIdentifier' => 'extension-luxletter-module',
        // 'icon' => 'EXT:luxletter/Resources/Public/Icons/lux_module_newsletter.svg',
        'labels' => 'LLL:EXT:luxletter_receiver_import/Resources/Private/Language/locallang_mod_receiverimport.xlf',
        'extensionName' => 'LuxletterReceiverImport',
        'position' => 'bottom',
        'controllerActions' => [
            \TRAW\LuxletterReceiverImport\Controller\ReceiverimportController::class => [
                'index',
            ],
        ],
    ],

];
