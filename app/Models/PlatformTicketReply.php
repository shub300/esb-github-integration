<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformTicketReply extends Model
{
    protected $table = 'platform_ticket_replies';

    public function ticketAttachment()
    {
        return $this->hasMany(PlatformTicketAttachment::class, 'reply_id' , 'reply_id')->orderBy( 'index', 'ASC' );
    }
}
