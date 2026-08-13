<?php

namespace justinholtweb\microscope\records;

use craft\db\ActiveRecord;
use justinholtweb\microscope\migrations\Install;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property int $scanId
 * @property string $checkHandle
 * @property string $category
 * @property string $severity
 * @property string $title
 * @property string|null $summary
 * @property string|null $observed
 * @property string|null $expected
 * @property string|null $impact
 * @property string|null $remediation
 * @property string|null $docsUrl
 * @property string|null $target
 * @property int $occurrences
 * @property string|null $evidence
 */
class FindingRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Install::TABLE_FINDINGS;
    }

    public function getScan(): ActiveQueryInterface
    {
        return $this->hasOne(ScanRecord::class, ['id' => 'scanId']);
    }
}
