<?php

namespace App\Contracts;

interface SequenceRepository
{
    

    public function generate(
        string $type,
        string $modelClass,
        ?string $dateString = null,
        ?string $modelColumn = 'number',
    ): string;
}
