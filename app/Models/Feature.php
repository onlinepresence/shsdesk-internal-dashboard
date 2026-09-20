<?php

namespace App\Models;

use Database\Factories\FeatureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['label', 'description', 'kind', 'locked', 'default_on', 'base_price', 'renewal_base', 'active'])]
class Feature extends Model
{
    /** @use HasFactory<FeatureFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'locked' => 'boolean',
            'default_on' => 'boolean',
            'base_price' => 'decimal:2',
            'renewal_base' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    /**
     * FlowEdu quote-module keys mapped to canonical db_column keys.
     * Transcribed verbatim from the live FlowEdu config/licence.php
     * `modules` block (key → db_column) on 2026-09-20. Do not derive
     * this by stripping prefixes: the map is the contract with the
     * sender, and catalogue churn must never 422 a legitimate quote.
     *
     * @var array<string, string>
     */
    public const FLOWEDU_MODULE_MAP = [
        'finance' => 'module_finance',
        'staff_hr' => 'module_staff_hr',
        'reports' => 'module_reports',
        'evaluations' => 'module_evaluations',
        'student_welfare' => 'module_student_welfare',
        'progression' => 'module_progression',
        'system_admin' => 'module_system_admin',
        'teacher_tools' => 'module_teacher_tools',
        'messaging' => 'module_messaging',
        'practicum' => 'module_practicum',
    ];

    /**
     * Features currently offered (the only ones new grants may enable).
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
