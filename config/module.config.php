<?php declare(strict_types=1);

namespace Zip;

return [
    'form_elements' => [
        'invokables' => [
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
        ],
    ],
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
    'zip' => [
        'settings' => [
            'zip_items' => '',
            'zip_original' => 0,
            'zip_large' => 300,
            'zip_medium' => 2000,
            'zip_square' => 2000,
            'zip_asset' => 1000,
            'zip_list_zip' => true,
        ],
    ],
];
