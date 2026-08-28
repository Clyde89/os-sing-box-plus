<?php

namespace OPNsense\SingBox\FieldTypes;

use OPNsense\Base\FieldTypes\BaseField;
use OPNsense\Base\Validators\CallbackValidator;
use OPNsense\SingBox\Validation\SelectionValidator;

class DomainListField extends BaseField
{
    protected $internalIsContainer = false;
    protected $internalValidationMessage = 'Список доменов содержит некорректные значения.';

    public function getValidators()
    {
        $validators = parent::getValidators();
        if ($this->internalValue !== null && $this->internalValue !== '') {
            $validators[] = new CallbackValidator([
                'callback' => static function ($data) {
                    return SelectionValidator::validateDomains((string)$data);
                },
            ]);
        }
        return $validators;
    }
}
