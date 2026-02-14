<?php

namespace HibaSabouh\ModelTranslations\Exceptions;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class InvalidTranslationFormatException extends BadRequestHttpException
{
    public function __construct(string $attribute)
    {
        parent::__construct(
            "The '{$attribute}' attribute must be an array of translations in the format: ['locale' => 'value']."
        );
    }
}
