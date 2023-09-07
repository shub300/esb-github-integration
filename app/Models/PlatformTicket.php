<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformTicket extends Model
{
    protected $table = 'platform_tickets';

    public function ticketAttachment()
    {
        return $this->hasMany(PlatformTicketAttachment::class, 'ticket_id' , 'id')->orderBy( 'index', 'ASC' );
    }

    public function ticketReplay()
    {
        return $this->hasMany(PlatformTicketReply::class, 'ticket_id' , 'id')->orderBy( 'replay_id', 'ASC' );
    }
}
