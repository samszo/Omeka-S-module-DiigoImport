<?php
namespace DiigoImport\Form;

use Omeka\Form\Element\ItemSetSelect;
use Zend\Form\Element;
use Zend\Form\Form;
use Zend\Validator\Callback;

class ImportForm extends Form
{
    public function init()
    {
        $this->add([
            'name' => 'itemSet',
            'type' => ItemSetSelect::class,
            'options' => [
                'label' => 'Import into', // @translate
                'info' => 'Required. Import items into this item set.', // @translate
                'empty_option' => 'Select item set…', // @translate
                'query' => ['is_open' => true],
            ],
            'attributes' => [
                'required' => true,
                'class' => 'chosen-select',
                'id' => 'library-item-set',
            ],
        ]);

        $this->add([
            'name' => 'user',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Library user ID', // @translate
                'info' => 'Required. The user ID is the name after "https://www.diigo.com/user/" url.', // @translate
            ],
            'attributes' => [
                'required' => true,
                'id' => 'library-user',
            ],
        ]);

        $this->add([
            'name' => 'login',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'API login', // @translate
                'info' => 'Required. The user login for API.', // @translate
            ],
            'attributes' => [
                'required' => true,
                'id' => 'api-login',
            ],
        ]);

        $this->add([
            'name' => 'pwd',
            'type' => Element\Password::class,
            'options' => [
                'label' => 'API password', // @translate
                'info' => 'Required. The user password for API.', // @translate
            ],
            'attributes' => [
                'required' => true,
                'id' => 'api-password',
            ],
        ]);

        $this->add([
            'name' => 'apiKey',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'API Key', // @translate
                'info' => 'Required for non-public libraries and file import.', // @translate
            ],
            'attributes' => [
                'required' => true,
                'id' => 'api-key',
            ],
        ]);

        $this->add([
            'name' => 'numStart',
            'type' => Element\Number::class,
            'options' => [
                'label' => 'start id', // @translate
                'info' => 'To resume an import.', // @translate
            ],
            'attributes' => [
                'required' => false,
                'id' => 'num-page',
            ],
        ]);

        $this->add([
            'name' => 'importFiles',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Import Files', // @translate
                'info' => 'The API key is required to import files.', // @translate
            ],
            'attributes' => [
                'id' => 'import-files',
            ],
        ]);

        $this->add([
            'name' => 'addedAfter',
            'type' => Element\DateTimeLocal::class,
            'options' => [
                'format' => 'Y-m-d\TH:i',
                'label' => 'Added after', // @translate
                'info' => 'Only import items that have been added to Diigo after this datetime.', // @translate
            ],
            'attributes' => [
                'id' => 'added-after',
            ],
        ]);

        $this->add([
            'name' => 'what',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'What', // @translate
                'info' => 'Specify a request.', // @translate
            ],
            'attributes' => [
                'required' => false,
                'id' => 'what-query',
            ],
        ]);

        $inputFilter = $this->getInputFilter();

        $inputFilter->add([
            'name' => 'itemSet',
            'required' => true,
            'filters' => [
                ['name' => 'Int'],
            ],
            'validators' => [
                ['name' => 'Digits'],
            ],
        ]);

        $inputFilter->add([
            'name' => 'user',
            'required' => true,
            'filters' => [
                ['name' => 'StringTrim'],
                ['name' => 'Null'],
            ],
        ]);

        $inputFilter->add([
            'name' => 'login',
            'required' => false,
            'filters' => [
                ['name' => 'StringTrim'],
                ['name' => 'Null'],
            ],
        ]);

        $inputFilter->add([
            'name' => 'pwd',
            'required' => false,
            'filters' => [
                ['name' => 'StringTrim'],
                ['name' => 'Null'],
            ],
        ]);

        $inputFilter->add([
            'name' => 'apiKey',
            'required' => true,
            'filters' => [
                ['name' => 'StringTrim'],
                ['name' => 'Null'],
            ],
        ]);

        $inputFilter->add([
            'name' => 'numStart',
            'required' => false,
            'filters' => [
                ['name' => 'ToInt'],
            ],
        ]);

        $inputFilter->add([
            'name' => 'what',
            'required' => false,
            'filters' => [
                ['name' => 'StringTrim'],
                ['name' => 'Null'],
            ],
        ]);

        $inputFilter->add([
            'name' => 'importFiles',
            'required' => false,
            'filters' => [
                ['name' => 'ToInt'],
            ],
            'validators' => [
                [
                    'name' => 'Callback',
                    'options' => [
                        'messages' => [
                            Callback::INVALID_VALUE => 'An API key is required to import files.', // @translate
                        ],
                        'callback' => function ($importFiles, $context) {
                            return $importFiles ? (bool) $context['apiKey'] : true;
                        },
                    ],
                ],
            ],
        ]);

        $inputFilter->add([
            'name' => 'addedAfter',
            'required' => false,
        ]);
    }
}
