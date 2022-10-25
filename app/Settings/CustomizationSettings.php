<?php

namespace App\Settings;


use Spatie\LaravelSettings\Settings;

class CustomizationSettings extends Settings
{

    public string $primary_color;
    public string $secondary_color;
    public string $tertiary_color;
    public ?bool $logo_enabled = false;

    public static function group(): string
    {
        return 'customization';
    }

}
