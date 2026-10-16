<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SMSCode extends Model
{
    use HasFactory;

    protected $table = 'sms_codes_disabled';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'phone_no',
        'code',
        'status',
        'body',
    ];

    /**
     * @deprecated This feature is disabled. The SMSCode feature has not been
     * implemented yet. This model is retained only to preserve the table
     * structure for future use.
     */
    public static function generateCode(): string
    {
        abort(503, 'SMS verification is not available.');
    }
}
