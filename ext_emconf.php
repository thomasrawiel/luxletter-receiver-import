<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Luxletter Receiver Import',
    'description' => 'Backend module receiver import form',
    'category' => 'backend',
    'author' => 'Thomas Rawiel',
    'author_email' => 'thomas.rawiel@gmail.com',
    'state' => 'stable',
    'version' => '1.1.2',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
            'luxletter' => '13.0.0-',
            'news' =>'9.4.0-'
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
