<?php

namespace App\Contracts;

interface SettingsRepository
{
    

    public function get(): array;

    

    public function put(array $settings): void;
}
