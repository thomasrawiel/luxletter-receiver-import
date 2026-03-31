<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Luxletter Receiver Import',
    'description' => 'Backend module receiver import form',
    'category' => 'backend',
    'author' => 'Thomas Rawiel',
    'author_email' => 'thomas.rawiel@gmail.com',
    'state' => 'stable',
    'version' => '1.3.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-13.4.99',
            'luxletter' => '26.0.0-29.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
