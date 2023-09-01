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
            'typo3' => '11.5.0-12.99.99'
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
    'author'           => 'Susanne Moog, Steffen Frese, Tobias Kretschmann',
    'author_email'     => 's.moog@neusta.de, s.frese@neusta.de, t.kretschmann@neusta.de',
    'author_company'   => 'Neusta GmbH',
    'version'          => '8.0.0',
];
