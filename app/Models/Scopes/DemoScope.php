<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Memisahkan data demo dari data nyata pada instance yang sama:
 * - user dengan role demo hanya melihat baris is_demo = true,
 * - selain itu (termasuk konteks console/queue tanpa auth) hanya is_demo = false.
 */
class DemoScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        $builder->where(
            $model->getTable().'.is_demo',
            (bool) ($user?->isDemo())
        );
    }
}
