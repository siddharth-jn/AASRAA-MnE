<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AuthoritativeProject extends Model
{
    protected $connection = 'projects_source';

    protected $table = 'projects';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'created_at' => 'datetime',
            'started_date' => 'date',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), 'Active');
    }

    public function scopeOperational(Builder $query): Builder
    {
        return $query->active()->withoutDemoProgramme();
    }

    public function scopeWithoutDemoProgramme(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereNull($this->qualifyColumn('program'))
                ->orWhereRaw('lower(trim('.$this->qualifyColumn('program').')) <> ?', ['demo']);
        });
    }
}
