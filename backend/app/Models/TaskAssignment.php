<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Model;

class TaskAssignment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'task_id',
        'user_id',
        'is_responsible',
        'assigned_at',
    ];

    protected $casts = [
        'is_responsible' => 'boolean',
        'assigned_at' => 'datetime',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
