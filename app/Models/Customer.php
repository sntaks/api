<?php

namespace App\Models;

use App\Util\Badge;
use App\Util\PlayerNumber;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'customers';

    protected $fillable = [
        'account_no',
        'name',
        'id_no',
        'phone_no',
        'referral_code',
        'email',
        'email_verified_at',
        'status',
    ];

    /**
     * Internal lookup key for matching hashed M-Pesa payer numbers; never part of an API response.
     *
     * @var list<string>
     */
    protected $hidden = [
        'phone_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phone_no_verified_at' => 'datetime',
        ];
    }

    /**
     * The promo code the customer signed up with, if it was valid then.
     */
    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    protected static function boot()
    {
        parent::boot();

        // static::created(function($customer){
        // Generate account number: yearmonth + 4-digit customer ID
        // $yearMonth = now()->format('Ym'); // Current year and month (e.g., 202501)
        // $customerId = str_pad($customer->id, 4, '0', STR_PAD_LEFT); // Pad customer ID to 4 digits
        // $customer->account_no = $yearMonth . $customerId;
        // $customer->save(); // Save the updated account number

        // Get phone number in the format 2547XXXXXXXX
        // Remove the first 3 characters to get 7XXXXXXXX
        /*if (isset($customer->phone_no) && strlen($customer->phone_no) >= 10) {
            $customer->account_no = substr($customer->phone_no, 3);
            $customer->save(); // Save the updated account number
        }*/
        // });

        static::creating(function (Customer $customer) {
            $customer->player_no ??= PlayerNumber::generateUnique();
        });

        static::saving(function (Customer $customer) {
            if ($customer->isDirty('phone_no') || ! $customer->exists) {
                $customer->phone_hash = self::phoneHash($customer->phone_no);
            }

            // A different number is not verified yet; the same number reformatted keeps its verification.
            if ($customer->exists && $customer->isDirty('phone_hash')) {
                $customer->phone_no_verified_at = null;
            }
        });
    }

    /**
     * A Kenyan mobile number in the form M-Pesa uses (2547XXXXXXXX or 2541XXXXXXXX), from any of the ways it
     * is stored or typed (+254..., 254..., 07..., 7...), or null when it is not one.
     */
    public static function canonicalPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return preg_match('/^(?:254|0)?([17]\d{8})$/', $digits, $match) ? '254'.$match[1] : null;
    }

    /**
     * SHA-256 of the canonical phone number: how Safaricom hides the payer's number (`msisdn`) in C2B
     * confirmations, so a hashed payer can be matched to a customer.
     */
    public static function phoneHash(?string $phone): ?string
    {
        $canonical = self::canonicalPhone($phone);

        return $canonical === null ? null : hash('sha256', $canonical);
    }

    /**
     * Exclude the test customers listed in config('finance.test_customer_ids').
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeExcludingTest(Builder $query): Builder
    {
        return $query->whereNotIn($query->qualifyColumn('id'), config('finance.test_customer_ids'));
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    public function coin()
    {
        return $this->hasOne(Coin::class);
    }

    public function gameWalletTransactions()
    {
        return $this->hasMany(GameTransaction::class);
    }

    public function walletTransactions()
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function competitionWalletTransactions()
    {
        return $this->hasMany(CompetitionTransaction::class);
    }

    public function competitionWallet(): Customer|HasMany
    {
        return $this->hasMany(CompetitionWallet::class);
    }

    public function allCompetitionTransactions(): HasManyThrough
    {
        return $this->hasManyThrough(
            CompetitionTransaction::class,
            CompetitionWallet::class,
            'customer_id', // Foreign key on competition_wallets table
            'competition_wallet_id', // Foreign key on competition_transactions table
            'id', // Local key on customers table
            'id' // Local key on competition_wallets table
        );
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /**
     * The customer's own code, shared to invite others.
     */
    public function referralCode(): HasOne
    {
        return $this->hasOne(ReferralCode::class);
    }

    public function referralWallet(): HasOne
    {
        return $this->hasOne(ReferralWallet::class);
    }

    /**
     * Customers who signed up with this customer's code.
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'referrer_id');
    }

    /**
     * How this customer was referred, if they signed up with another customer's code.
     */
    public function referredBy(): HasOne
    {
        return $this->hasOne(Referral::class, 'referred_id');
    }

    /**
     * Get the status badge HTML for this role.
     */
    public function getStatusBadge(): string
    {
        return match ($this->status) {
            1 => Badge::set('primary', 'Active'),
            2 => Badge::set('danger', 'Blocked'),
            3 => Badge::set('secondary', 'Confirmed'),
            4 => Badge::set('default', 'Deleted'),
            5 => Badge::set('warning', 'Fraud'),
            6 => Badge::set('info', 'Lead'),
            default => Badge::set('secondary', 'NONE'),
        };
    }
}
