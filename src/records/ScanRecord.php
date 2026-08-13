<?php

namespace justinholtweb\microscope\records;

use craft\db\ActiveRecord;
use justinholtweb\microscope\migrations\Install;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property string $status
 * @property string $trigger
 * @property string|null $config
 * @property string|null $environment
 * @property int|null $score
 * @property string|null $categoryScores
 * @property int $criticalCount
 * @property int $warningCount
 * @property int $noticeCount
 * @property int $passCount
 * @property string|null $skipped
 * @property float|null $runtime
 * @property string|null $error
 * @property int|null $creatorId
 * @property string|null $dateStarted
 * @property string|null $dateFinished
 */
class ScanRecord extends ActiveRecord
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public static function tableName(): string
    {
        return Install::TABLE_SCANS;
    }

    public function getFindings(): ActiveQueryInterface
    {
        return $this->hasMany(FindingRecord::class, ['scanId' => 'id']);
    }
}
