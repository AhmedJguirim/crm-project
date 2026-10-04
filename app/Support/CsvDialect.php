<?php

namespace App\Support;

/**
 * How a CSV file is written: its column delimiter and its character encoding.
 */
final readonly class CsvDialect
{
    public function __construct(
        public string $delimiter = ',',
        public string $encoding = 'UTF-8',
    ) {}

    /**
     * A file with `;` between columns, as European versions of Excel write them. Their numbers and dates are written
     * the local way too.
     */
    public function isEuropean(): bool
    {
        return $this->delimiter === ';';
    }
}
