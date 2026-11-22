<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Department  extends Model
{
    protected $table = 'department';

    public Function users()
    {
        return $this->hasMany(Uesr::class);
    }

}
