<?php declare(strict_types=1);

namespace Deduplicate;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\DeduplicateForm::class => Form\DeduplicateForm::class,
        ],
    ],
    'controllers' => [
        'invokables' => [
            'Deduplicate\Controller\Index' => Controller\IndexController::class,
        ],
    ],
    // TODO Remove these routes and use main admin/default.
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'deduplicate' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/deduplicate',
                            'constraints' => [
                                'action' => 'index|manual',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'Deduplicate\Controller',
                                '__ADMIN__' => true,
                                'controller' => 'Index',
                                'action' => 'index',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            'deduplicate' => [
                'label' => 'Deduplicate', // @translate
                'route' => 'admin/deduplicate',
                'controller' => 'index',
                'resource' => 'Omeka\Controller\Admin\Item',
                'privilege' => 'batch-delete',
                'class' => 'o-icon- fa-clone',
            ],
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
    'js_translate_strings' => [
        'Go', // @translate
    ],
    'deduplicate' => [
    ],
];
