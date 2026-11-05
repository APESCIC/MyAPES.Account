<?php

namespace App\Core\Accounts\ClientRecords;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Hides archived client records everywhere unless a query asks for them (#514).
 */
final class ClientRecordArchiveScope implements Scope
{
    public const COLUMN = 'archived_at';

    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull($model->qualifyColumn(self::COLUMN));
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withArchived', static fn (Builder $builder): Builder => $builder->withoutGlobalScope(self::class));

        $builder->macro('onlyArchived', static fn (Builder $builder): Builder => $builder
            ->withoutGlobalScope(self::class)
            ->whereNotNull($builder->getModel()->qualifyColumn(self::COLUMN)));
    }
}
