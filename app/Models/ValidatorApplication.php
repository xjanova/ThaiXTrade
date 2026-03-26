<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ValidatorApplication Model.
 *
 * Represents a validator node application for the TPIX Chain network.
 * Tracks applications from submission through review to activation.
 *
 * @property int $id
 * @property string $wallet_address
 * @property string $tier
 * @property string|null $endpoint
 * @property string $country_code
 * @property string $country_name
 * @property float $latitude
 * @property float $longitude
 * @property string|null $contact_email
 * @property string|null $contact_telegram
 * @property string|null $hardware_specs
 * @property string|null $motivation
 * @property string $status
 * @property string|null $admin_notes
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AdminUser|null $reviewer
 * @property-read string $tier_display_name
 */
class ValidatorApplication extends Model
{
    use HasFactory;

    // =========================================================================
    // Constants
    // =========================================================================

    /** @var string Tier: IBFT2 Validator node (block sealer + governance, 10M TPIX, company KYC) */
    public const TIER_VALIDATOR = 'validator';

    /** @var string Tier: Guardian node (premium masternode, 1M TPIX) */
    public const TIER_GUARDIAN = 'guardian';

    /** @var string Tier: Sentinel node (standard masternode, 100K TPIX) */
    public const TIER_SENTINEL = 'sentinel';

    /** @var string Tier: Light node (minimal resource participation, 10K TPIX) */
    public const TIER_LIGHT = 'light';

    /** @var array<string, string> All available tiers with display names */
    public const TIERS = [
        self::TIER_VALIDATOR => 'Validator Node',
        self::TIER_GUARDIAN => 'Guardian Node',
        self::TIER_SENTINEL => 'Sentinel Node',
        self::TIER_LIGHT => 'Light Node',
    ];

    /** @var string Status: Awaiting admin review */
    public const STATUS_PENDING = 'pending';

    /** @var string Status: Approved by admin, awaiting activation */
    public const STATUS_APPROVED = 'approved';

    /** @var string Status: Rejected by admin */
    public const STATUS_REJECTED = 'rejected';

    /** @var string Status: Node is live and active on the network */
    public const STATUS_ACTIVE = 'active';

    /** @var array<int, string> All available statuses */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_ACTIVE,
    ];

    // =========================================================================
    // Model Configuration
    // =========================================================================

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'validator_applications';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'wallet_address',
        'tier',
        'endpoint',
        'country_code',
        'country_name',
        'latitude',
        'longitude',
        'contact_email',
        'contact_telegram',
        'hardware_specs',
        'motivation',
        'status',
        'admin_notes',
        'reviewed_by',
        'reviewed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'reviewed_at' => 'datetime',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * Get the admin user who reviewed this application.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to only include pending applications.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope a query to only include approved applications.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Scope a query to only include active applications.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    // =========================================================================
    // Accessors
    // =========================================================================

    /**
     * Get the human-readable display name for the tier.
     */
    public function getTierDisplayNameAttribute(): string
    {
        return self::TIERS[$this->tier] ?? ucfirst($this->tier);
    }
}
