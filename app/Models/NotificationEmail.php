<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationEmail extends Model
{
    public $timestamps = true;

    protected $table = 'es_notification_email';

    protected $fillable = ['user_id', 'emails'];
}
