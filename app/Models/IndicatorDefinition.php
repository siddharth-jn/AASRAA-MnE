<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IndicatorDefinition extends ImpactModel
{
    /** @var array<int, string> */
    public const HISTORICAL_CONFIGURATION_FIELDS = [
        'project_id',
        'code',
        'category',
        'unit',
        'data_type',
        'value_required',
        'calculation_method',
        'numerator_label',
        'denominator_label',
        'min_value',
        'max_value',
        'minimum_date',
        'maximum_date',
        'precision',
        'max_length',
        'options',
        'frequency',
        'reporting_scope',
        'effective_date',
        'time_aggregation',
        'centre_aggregation',
        'direction',
        'target_tracking',
        'evidence_required',
        'allowed_extensions',
        'max_file_kb',
        'min_files',
        'max_files',
        'evidence_review_required',
        'approval_required',
    ];

    protected function casts(): array
    {
        return parent::casts() + [
            'options' => 'array',
            'allowed_extensions' => 'array',
            'evidence_required' => 'boolean',
            'evidence_review_required' => 'boolean',
            'approval_required' => 'boolean',
            'value_required' => 'boolean',
            'target_tracking' => 'boolean',
            'effective_date' => 'date',
            'minimum_date' => 'date',
            'maximum_date' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function centres(): BelongsToMany
    {
        return $this->belongsToMany(Centre::class, 'indicator_assignments')->withPivot('required', 'is_demo');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(IndicatorAssignment::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(IndicatorDefinitionVersion::class);
    }

    public function targets(): HasManyThrough
    {
        return $this->hasManyThrough(IndicatorTarget::class, IndicatorAssignment::class);
    }

    public function isEffectiveForPeriod(CarbonInterface|string $period): bool
    {
        $reportingMonth = $period instanceof CarbonInterface
            ? CarbonImmutable::instance($period)->startOfMonth()
            : CarbonImmutable::parse($period)->startOfMonth();

        return $reportingMonth->gte(CarbonImmutable::parse($this->effective_date)->startOfMonth());
    }

    public function isProgrammeScoped(): bool
    {
        return $this->reporting_scope === 'programme';
    }

    public function hasHistoricalData(): bool
    {
        return $this->assignments()
            ->where(function ($query): void {
                $query->whereHas('targets')->orWhereHas('entries');
            })
            ->exists();
    }

    public function deleteIfUnused(): bool
    {
        if ($this->hasHistoricalData()) {
            throw ValidationException::withMessages([
                'indicator' => 'This indicator has targets or entries and cannot be permanently deleted. Archive it instead so its reporting history remains intact.',
            ]);
        }

        return DB::transaction(function (): bool {
            $this->assignments()->delete();
            $this->versions()->delete();

            return (bool) $this->delete();
        });
    }

    protected static function booted(): void
    {
        parent::booted();
        static::updating(function (self $indicator): void {
            $errors = [];

            if ($indicator->isDirty('project_id')) {
                $errors['project_id'] = 'The programme cannot be changed after an indicator is created.';
            }
            if ($indicator->isDirty('code')) {
                $errors['code'] = 'The permanent indicator code cannot be changed. Create a replacement indicator instead.';
            }

            if ($indicator->hasHistoricalData()) {
                $changedConfigurationFields = collect(self::HISTORICAL_CONFIGURATION_FIELDS)
                    ->reject(fn (string $field): bool => in_array($field, ['project_id', 'code'], true))
                    ->filter(fn (string $field): bool => $indicator->isDirty($field));

                foreach ($changedConfigurationFields as $field) {
                    $errors[$field] = 'This setting is locked because the indicator has targets or entries. Archive it and create a replacement indicator from a new month.';
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        });
        static::saving(function (self $indicator): void {
            if (! $indicator->active || blank($indicator->name) || blank($indicator->category)) {
                return;
            }

            $conflictingCategory = self::query()
                ->where('active', true)
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($indicator->name))])
                ->where('category', '!=', $indicator->category)
                ->when($indicator->exists, fn ($query) => $query->whereKeyNot($indicator->getKey()))
                ->exists();

            if ($conflictingCategory) {
                throw ValidationException::withMessages([
                    'category' => 'An active indicator with this name already uses another category. Keep one category for the same indicator concept, or use a distinct indicator name.',
                ]);
            }
        });
        static::saved(function (self $indicator): void {
            if ($indicator->wasRecentlyCreated || $indicator->wasChanged()) {
                $indicator->versions()->create(['version' => ($indicator->versions()->max('version') ?? 0) + 1, 'definition' => $indicator->attributesToArray(), 'is_demo' => (bool) $indicator->is_demo]);
            }
        });
    }
}
