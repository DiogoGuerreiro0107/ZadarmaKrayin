<?php

namespace Webkul\Zadarma\Models;

use Illuminate\Database\Eloquent\Model;

class ZadarmaAccount extends Model
{
    /**
     * Table name.
     *
     * @var string
     */
    protected $table = 'zadarma_accounts';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'api_key',
        'api_secret',
        'caller_extension',
        'sync_mode',
        'active',
        'last_synced_at',
    ];

    /**
     * The attributes that should be hidden for arrays/JSON.
     *
     * @var array
     */
    protected $hidden = [
        'api_key',
        'api_secret',
    ];

    /**
     * The attributes that are castable.
     *
     * @var array
     */
    protected $casts = [
        'api_key' => 'encrypted',
        'api_secret' => 'encrypted',
        'active' => 'boolean',
        'last_synced_at' => 'datetime',
    ];
}
