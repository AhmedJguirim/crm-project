<?php

namespace App\Services\Imports;

use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use Spatie\SimpleExcel\SimpleExcelReader;

/**
 * A reader that keeps a text cell starting with `=`.
 *
 * OpenSpout reads every string that starts with `=` as a formula, and a formula without a stored result reads as
 * empty. A text cell such as `=1+1` (an exported contact named like that) therefore came back empty. A cell with a
 * stored formula result is still read as that result; only a formula without one is read as its text.
 */
class TextPreservingExcelReader extends SimpleExcelReader
{
    /**
     * @return array<int|string, mixed>
     */
    protected function getValueFromRow(Row $row): array
    {
        foreach ($row->getCells() as $index => $cell) {
            if ($cell instanceof FormulaCell && $cell->getComputedValue() === null) {
                $row->setCellAtIndex(new StringCell($cell->getValue(), $cell->getStyle()), $index);
            }
        }

        return parent::getValueFromRow($row);
    }
}
