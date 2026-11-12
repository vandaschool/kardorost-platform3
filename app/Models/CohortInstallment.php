<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CohortInstallment extends Model
{
    use HasFactory;

    protected $fillable = ['cohort_id', 'sequence', 'amount', 'due_date'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'due_date' => 'date:Y-m-d'];
    }
}
