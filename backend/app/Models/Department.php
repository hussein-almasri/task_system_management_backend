<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'description'])]
class Department extends Model
{
    protected $table = 'department';

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
