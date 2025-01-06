<?php declare(strict_types=1);

namespace Zip\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;

class SettingsFieldset extends Fieldset
{
    protected $label = 'Zip resources'; // @translate

    protected $elementGroups = [
        'zip' => 'Zip', // @translate
        'jobs' => 'Jobs', // @translate
    ];

    public function init(): void
    {
        $this
            ->setAttribute('id', 'zip')
            ->setOption('element_groups', $this->elementGroups)
            ->add([
                'name' => 'zip_items',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'element_group' => 'zip',
                    'label' => 'Create a zip by item for derivatives', // @translate
                    'value_options' => [
                        '' => 'No', // @translate
                        'original' => 'Original', // @translate
                        'large' => 'Large',  // @translate
                        'medium' => 'Medium', // @translate
                        'square' => 'Square', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'zip_items',
                ],
            ])
            ->add([
                'name' => 'zip_original',
                'type' => CommonElement\OptionalNumber::class,
                'options' => [
                    'element_group' => 'zip',
                    'label' => 'Create a zip for original files: Number of files by zip', // @translate
                ],
                'attributes' => [
                    'id' => 'zip_original',
                    'min' => '0',
                ],
            ])
            ->add([
                'name' => 'zip_large',
                'type' => CommonElement\OptionalNumber::class,
                'options' => [
                    'element_group' => 'zip',
                    'label' => 'Create a zip for large files: Number of files by zip', // @translate
                ],
                'attributes' => [
                    'id' => 'zip_large',
                    'min' => '0',
                ],
            ])
            ->add([
                'name' => 'zip_medium',
                'type' => CommonElement\OptionalNumber::class,
                'options' => [
                    'element_group' => 'zip',
                    'label' => 'Create a zip for medium files: Number of files by zip', // @translate
                ],
                'attributes' => [
                    'id' => 'zip_medium',
                    'min' => '0',
                ],
            ])
            ->add([
                'name' => 'zip_square',
                'type' => CommonElement\OptionalNumber::class,
                'options' => [
                    'element_group' => 'zip',
                    'label' => 'Create a zip for square files: Number of files by zip', // @translate
                ],
                'attributes' => [
                    'id' => 'zip_square',
                    'min' => '0',
                ],
            ])
            ->add([
                'name' => 'zip_asset',
                'type' => CommonElement\OptionalNumber::class,
                'options' => [
                    'element_group' => 'zip',
                    'label' => 'Create a zip for asset files: Number of files by zip', // @translate
                ],
                'attributes' => [
                    'id' => 'zip_asset',
                    'min' => '0',
                ],
            ])
            ->add([
                'name' => 'zip_list_zip',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'zip',
                    'label' => 'Add file "zipfiles.txt" with the list of zip files', // @translate
                ],
                'attributes' => [
                    'id' => 'zip_list_zip',
                ],
            ])
            ->add([
                'name' => 'zip_job',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'zip',
                    'label' => 'Run background task to create zip files (or use EasyAdmin)', // @translate
                ],
                'attributes' => [
                    'id' => 'zip_job',
                ],
            ])
        ;
    }
}
