<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

abstract class ImpactModel extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_demo' => 'boolean', 'active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::created(fn (Model $model) => self::writeAuditLog($model, 'created'));
        static::updated(fn (Model $model) => self::writeAuditLog($model, 'updated'));
    }

    private static function writeAuditLog(Model $model, string $action): void
    {
        if (Auth::check() && ! $model instanceof AuditLog) {
            $changedAttributes = collect($model->getChanges())->except(['password', 'remember_token']);
            $changes = $action === 'created'
                ? ['after' => $changedAttributes->all()]
                : [
                    'before' => $changedAttributes->keys()->mapWithKeys(fn (string $key): array => [$key => $model->getOriginal($key)])->all(),
                    'after' => $changedAttributes->all(),
                ];
            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => $action,
                'subject_type' => $model::class,
                'subject_id' => $model->getKey(),
                'changes' => $changes,
                'is_demo' => (bool) ($model->is_demo ?? false),
            ]);
        }
    }
}
