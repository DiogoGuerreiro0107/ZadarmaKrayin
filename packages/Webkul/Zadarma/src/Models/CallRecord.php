<?php

namespace Webkul\Zadarma\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Contact\Models\Person;

class CallRecord extends Model
{
    /**
     * Table name.
     *
     * @var string
     */
    protected $table = 'call_records';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'external_id',
        'direction',
        'from_number',
        'to_number',
        'duration',
        'disposition',
        'recording_url',
        'person_id',
        'started_at',
    ];

    /**
     * The attributes that are castable.
     *
     * @var array
     */
    protected $casts = [
        'duration' => 'integer',
        'started_at' => 'datetime',
    ];

    /**
     * Get the person associated with the call record.
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }
}
