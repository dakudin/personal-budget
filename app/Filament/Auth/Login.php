<?php

namespace App\Filament\Auth;

use App\Rules\BcryptPassword;
use Filament\Schemas\Components\Component;

class Login extends \Filament\Auth\Pages\Login
{
    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()->rules([new BcryptPassword]);
    }
}
