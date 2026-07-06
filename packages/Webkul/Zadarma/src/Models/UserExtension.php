<?php

namespace Webkul\Zadarma\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\User\Models\User;

class UserExtension extends Model
{
    /**
     * Table name.
     *
     * @var string
     */
    protected $table = 'zadarma_user_extensions';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'extension',
    ];

    /**
     * Get the user that owns the extension.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
