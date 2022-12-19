<?php declare(strict_types=1);

namespace Deduplicate;

return [
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'js_translate_strings' => [
        'Deduplicate on:', // @translate
        'Property id', // @translate
        'Value', // @translate
    ],
    'deduplicate' => [
    ],
];
