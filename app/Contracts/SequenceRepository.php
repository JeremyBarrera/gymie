<?php

namespace App\Contracts;

/**
 * Sequence / number generation abstraction (e.g. Invoice / Member numbers).
 *
 * OSS default inspects the database itself: numbering always starts at 1
 * within the fiscal span and self-heals from existing rows. Other
 * installations can override this binding to generate sequences safely
 * (for example, using row locks).
 */
interface SequenceRepository
{
    /**
     * @param  class-string  $modelClass
     */
    public function generate(
        string $type,
        string $modelClass,
        ?string $dateString = null,
        ?string $modelColumn = 'number',
    ): string;
}
