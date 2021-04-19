<?php declare(strict_types=1);

namespace Zip\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;

class SettingsFieldset extends Fieldset
{
    protected $label = 'Zip resources'; // @translate

    public function init(): void
    {
        $this
            ->add([
                'name' => 'zip_original',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Number of original files to store by zip', // @translate
                    'info' => 'Set 0 not to create the zip.', // @translate
                ],
                'attributes' => [
                    'id' => 'zip_original',
                    'min' => '0',
                ],
            ])
            ->add([
                'name' => 'zip_large',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Number of large files to store by zip', // @translate
                    'info' => 'Set 0 not to create the zip.', // @translate
                ],
                'attributes' => [
                    'id' => 'zip_large',
                    'min' => '0',
                ],
            ])
            ->add([
                'name' => 'zip_medium',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Number of medium files to store by zip', // @translate
                    'info' => 'Set 0 not to create the zip.', // @translate
                ],
                'attributes' => [
                    'id' => 'zip_medium',
                    'min' => '0',
                ],
            ])
            ->add([
                'name' => 'zip_square',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Number of square files to store by zip', // @translate
                    'info' => 'Set 0 not to create the zip.', // @translate
                ],
                'attributes' => [
                    'id' => 'zip_square',
                    'min' => '0',
                ],
            ])
            ->add([
                'name' => 'zip_asset',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Number of asset files to store by zip', // @translate
                    'info' => 'Set 0 not to create the zip.', // @translate
                ],
                'attributes' => [
                    'id' => 'zip_asset',
                    'min' => '0',
                ],
            ])
            ->add([
                'name' => 'zip_job',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Create the zip now', // @translate
                ],
                'attributes' => [
                    'id' => 'zip_job',
                ],
            ])
        ;
    }
}
