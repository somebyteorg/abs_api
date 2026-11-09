<?php

namespace App\Models\Abs;

use App\Models;

abstract class AbsModel extends Models\Base
{
    protected $connection = 'abs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    public const CREATED_AT = 'createdat';

    public const UPDATED_AT = 'updatedat';

    /** Write the ABS primary keys already stored in the API, preserving native creation timestamps. */
    public static function syncById(array $records): void
    {
        if ($records === []) {
            return;
        }

        $model = new static;
        $timestamp = $model->freshTimestamp();
        $rows = array_map(function (array $attributes) use ($model, $timestamp) {
            // Query-builder upserts do not run Eloquent casts. Serialize JSON, booleans and dates first.
            return $model->newInstance()->forceFill([
                ...$attributes, static::CREATED_AT => $timestamp, static::UPDATED_AT => $timestamp,
            ])->getAttributes();
        }, $records);
        $updates = array_values(array_diff(array_keys($rows[0]), ['id', static::CREATED_AT]));
        foreach (array_chunk($rows, 500) as $batch) {
            static::query()->upsert($batch, ['id'], $updates);
        }
    }
}
