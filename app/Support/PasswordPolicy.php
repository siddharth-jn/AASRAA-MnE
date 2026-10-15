<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

class PasswordPolicy
{
    public const MINIMUM_LENGTH = 12;

    public static function rule(): Password
    {
        return Password::min(self::MINIMUM_LENGTH)
            ->letters()
            ->mixedCase()
            ->numbers();
    }

    public static function guidance(): string
    {
        return 'Use at least 12 characters, including uppercase and lowercase letters and a number.';
    }
}
