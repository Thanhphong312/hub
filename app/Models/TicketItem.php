<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketItem extends Model
{
    protected $fillable = ['ticket_id', 'send_by', 'content', 'attachments'];

    protected function casts(): array
    {
        return ['attachments' => 'array'];
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'send_by');
    }
}
