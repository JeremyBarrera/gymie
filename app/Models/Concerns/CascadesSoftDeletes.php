<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

trait CascadesSoftDeletes
{
    

    protected static function relationsToCascade(): array
    {
        return [];
    }

    

    protected static function bootCascadesSoftDeletes(): void
    {
        static::deleting(function (Model $model): void {
            foreach (static::relationsToCascade() as $relation) {
                $model->{$relation}()->get()->each->delete();
            }
        });

        static::restoring(function (Model $model): void {
            foreach (static::relationsToCascade() as $relation) {
                $model->{$relation}()->withTrashed()->get()->each->restore();
            }
        });
    }
}
