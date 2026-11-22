<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
  protected $fillable = [
    'title' ,
    'description'
  ];

    public function taskAssignments()
    {
        return $this->hasMany(TaskAssignment::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function histories()
    {
        return $this->hasMany(TaskHistory::class, 'task_id');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class, 'task_id');
    }

    public function comments()
    {
        return $this->hasMany(TaskComment::class);
    }

    public function attachments()
    {
        return $this->hasMany(TaskAttachment::class);
    }
}
