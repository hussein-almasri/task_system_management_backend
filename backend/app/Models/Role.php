<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = [
        'name' ,
        'description'
    ];
    public Function uesrs()
    {
        return $this->hasMany(Uesr::class) ;
    }
}
