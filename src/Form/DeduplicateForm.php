<?php declare(strict_types=1);

namespace Deduplicate\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class DeduplicateForm extends Form
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'deduplicate_property',
                'type' => OmekaElement\PropertySelect::class,
                'options' => [
                    'label' => 'Property to deduplicate on', // @translate
                    'empty_option' => '',
                    'term_as_value' => true,
                    'used_terms' => true,
                ],
                'attributes' => [
                    'id' => 'deduplicate-property',
                    'class' => 'chosen-select',
                    'required' => true,
                    'data-placeholder' => 'Select a property…', // @translate
                ],
            ])
            ->add([
                'name' => 'deduplicate_value',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Value', // @translate
                ],
                'attributes' => [
                    'id' => 'deduplicate-value',
                    'required' => true,
                ],
            ])
            ->add([
                'name' => 'method',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Heuristic to find similar values', // @translate
                    'value_options' => [
                        'similar_text' => 'Similar text', // @translate
                        'levenshtein' => 'Levenshtein', // @translate
                        'metaphone' => 'Metaphone', // @translate
                        'soundex' => 'Soundex', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'deduplicate-method',
                    'required' => false,
                    'value' => 'similar_text',
                ],
            ])
            /*
            ->add([
                'name' => 'resource_type',
                'type' => Element\Hidden::class,
                'attributes' => [
                    'value' => 'item',
                ],
            ])
            */
        ;

        $inputFilter = $this->getInputFilter();
        $inputFilter
            ->add([
                'name' => 'method',
                'required' => false,
            ])
        ;
    }
}
