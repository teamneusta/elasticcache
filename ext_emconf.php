<?php
/************************************************************************
 * Extension Manager/Repository config file for ext "elasticcache".
 ************************************************************************/
$EM_CONF[$_EXTKEY] = [
    'title'            => 'elasticcache',
    'description'      => 'Base extension for project "elasticcache"',
    'category'         => 'extension',
    'constraints'      => [
        'depends'   => [
            'typo3' => '8.6.0-9.99.99'
        ],
        'conflicts' => [
        ],
    ],
    'autoload'         => [
        'psr-4' => [
            'TeamNeusta\\Elasticcache\\' => 'Classes'
        ],
    ],
    'state'            => 'stable',
    'uploadfolder'     => 0,
    'createDirs'       => '',
    'clearCacheOnLoad' => 1,
    'author'           => 'Susanne Moog, Steffen Frese, Tobias Kretschmann',
    'author_email'     => 's.moog@neusta.de, s.frese@neusta.de, t.kretschmann@neusta.de',
    'author_company'   => 'Neusta GmbH',
    'version'          => '1.2.0',
];
