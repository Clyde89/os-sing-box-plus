<?php

namespace OPNsense\SingBox\FieldTypes;

use OPNsense\Base\FieldTypes\InterfaceField;
use OPNsense\Base\Validators\CallbackValidator;
use OPNsense\SingBox\Validation\SelectionValidator;

class CaptureInterfaceField extends InterfaceField
{
    protected $internalValidationMessage = 'Выбран недопустимый интерфейс захвата.';

    public function getValidators()
    {
        $validators = parent::getValidators();
        if ($this->internalValue !== null && $this->internalValue !== '') {
            $validators[] = new CallbackValidator([
                'callback' => static function ($data) {
                    return SelectionValidator::validateCaptureInterfaces($data);
                },
            ]);
        }
        return $validators;
    }
}
