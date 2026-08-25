<?php

namespace OPNsense\SingBox\FieldTypes;

use OPNsense\Base\FieldTypes\BaseField;
use OPNsense\Base\Validators\CallbackValidator;
use OPNsense\SingBox\Validation\SelectionValidator;

class ClientListField extends BaseField
{
    protected $internalIsContainer = false;
    protected $internalValidationMessage = 'Список клиентов содержит некорректные значения.';

    public function getValidators()
    {
        $validators = parent::getValidators();
        if ($this->internalValue !== null && $this->internalValue !== '') {
            $validators[] = new CallbackValidator([
                'callback' => static function ($data) {
                    return SelectionValidator::validateClients((string)$data);
                },
            ]);
        }
        return $validators;
    }
}
