<?php

namespace OPNsense\SingBox\FieldTypes;

use OPNsense\Base\FieldTypes\BaseField;
use OPNsense\Base\Validators\CallbackValidator;
use OPNsense\SingBox\Validation\SelectionValidator;

class FakeIpRangeField extends BaseField
{
    protected $internalIsContainer = false;
    protected $internalValidationMessage = 'Диапазон FakeIP IPv4 содержит некорректное значение.';

    public function getValidators()
    {
        $validators = parent::getValidators();
        if ($this->internalValue !== null && $this->internalValue !== '') {
            $validators[] = new CallbackValidator([
                'callback' => static function ($data) {
                    return SelectionValidator::validateIpv4Network((string)$data);
                },
            ]);
        }
        return $validators;
    }
}
