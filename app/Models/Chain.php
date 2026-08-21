<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Chain Model.
 *
 * Represents a blockchain network (e.g., Ethereum, BSC, Polygon).
 * Stores chain-specific configuration including RPC endpoints and explorer URLs.
 *
 * @property int $id
 * @property string $name
 * @property string $symbol
 * @property string|null $chain_id_hex
 * @property string $rpc_url
 * @property string|null $explorer_url
 * @property string|null $logo
 * @property bool $is_testnet
 * @property bool $is_active
 * @property string $native_currency_name
 * @property string $native_currency_symbol
 * @property int $native_currency_decimals
 * @property int $block_confirmations
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, Token> $tokens
 * @property-read Collection<int, TradingPair> $tradingPairs
 * @property-read Collection<int, SwapConfig> $swapConfigs
 * @property-read Collection<int, FeeConfig> $feeConfigs
 */
class Chain extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'chains';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'symbol',
        'chain_id_hex',
        'rpc_url',
        'explorer_url',
        'logo',
        'is_testnet',
        'is_active',
        'native_currency_name',
        'native_currency_symbol',
        'native_currency_decimals',
        'block_confirmations',
        'sort_order',
        // ── ย้ายมาจาก config/chains.php เพื่อให้แก้จากหลังบ้านได้จริง ──
        'chain_id',
        'network_id',
        'short_name',
        'status',
        'color',
        'gasless',
        'block_time',
        'consensus',
        'rpc_fallbacks',
    ];

    /** เชนที่เปิดเทรดได้จริง (กดเลือกได้ในหน้าเว็บ) */
    public const STATUS_LIVE = 'live';

    /** เห็นในลิสต์ได้ แต่กดเลือกไม่ได้ — รอระบบฝั่งเชนนั้นพร้อม */
    public const STATUS_COMING_SOON = 'coming_soon';

    /** ปิดชั่วคราวเพื่อซ่อมบำรุง */
    public const STATUS_MAINTENANCE = 'maintenance';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_testnet' => 'boolean',
            'is_active' => 'boolean',
            'gasless' => 'boolean',
            'chain_id' => 'integer',
            'network_id' => 'integer',
            'block_time' => 'integer',
            'rpc_fallbacks' => 'array',
        ];
    }

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = ['logo_url'];

    // =========================================================================
    // Accessors
    // =========================================================================

    /**
     * Logo URL — แปลงค่าในคอลัมน์ logo ให้เป็น URL ที่เบราว์เซอร์โหลดได้จริง.
     *
     * — URL เต็ม (http/https)        → คืนตรงๆ
     * — ขึ้นต้นด้วย /                → ไฟล์ใน public_html เช่น "/tpixlogo.webp"
     * — path เปล่า                   → ไฟล์ที่แอดมินอัปโหลด เช่น "chains/tpix.webp"
     *                                  ต้องผ่าน storage symlink
     *
     * ★ สาขาที่สามเคยขาดไป — เดิมมีแค่ http กับ asset() ตรงๆ
     *   ไฟล์ที่อัปโหลดจะถูกแปลงเป็น https://tpix.online/chains/xxx.webp
     *   ซึ่งไม่มีอยู่จริง (ของจริงอยู่ที่ /storage/chains/xxx.webp)
     *   ผลคือไอคอนที่เพิ่งอัปโหลด 404 ทุกใบ แล้ว @error ของ <img> สลับไปเป็น
     *   วงกลมตัวอักษร — ดูเหมือนอัปโหลดไม่สำเร็จ ทั้งที่ไฟล์ถูกเก็บเรียบร้อยแล้ว
     *
     *   ต้องตรงกับ Token::logoUrl() เสมอ เพราะทั้งสองใช้รูปแบบการอัปโหลดเดียวกัน
     */
    protected function logoUrl(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->logo) {
                return null;
            }

            if (str_starts_with($this->logo, 'http')) {
                return $this->logo;
            }

            // /xxx → ไฟล์ที่ public root (ไม่ผ่าน storage)
            if (str_starts_with($this->logo, '/')) {
                return asset(ltrim($this->logo, '/'));
            }

            // path เปล่า = ไฟล์ที่แอดมินอัปโหลด → ผ่าน storage symlink
            return asset('storage/'.$this->logo);
        });
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * Get the tokens on this chain.
     */
    public function tokens(): HasMany
    {
        return $this->hasMany(Token::class);
    }

    /**
     * Get the trading pairs on this chain.
     */
    public function tradingPairs(): HasMany
    {
        return $this->hasMany(TradingPair::class);
    }

    /**
     * Get the swap configurations for this chain.
     */
    public function swapConfigs(): HasMany
    {
        return $this->hasMany(SwapConfig::class);
    }

    /**
     * Get the fee configurations for this chain.
     */
    public function feeConfigs(): HasMany
    {
        return $this->hasMany(FeeConfig::class);
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to only include active chains.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include mainnet chains (exclude testnets).
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeMainnet($query)
    {
        return $query->where('is_testnet', false);
    }

    /**
     * Scope a query to order chains by their sort order.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeOrdered($query, string $direction = 'asc')
    {
        return $query->orderBy('sort_order', $direction);
    }

    /**
     * Scope: เชนที่เปิดเทรดได้จริงเท่านั้น.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeLive($query)
    {
        return $query->where('status', self::STATUS_LIVE);
    }

    // =========================================================================
    // API projection
    // =========================================================================

    /**
     * แปลงแถวในฐานข้อมูลให้เป็นรูปแบบเดียวกับที่ /api/v1/chains เคยส่งจาก config.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * ★ รูปแบบนี้ห้ามเปลี่ยนพลการ — มีคนอ่านอยู่สามฝั่ง
     * ═══════════════════════════════════════════════════════════════════════
     *   - เว็บ: ChainSelector.vue อ่าน status/chainId/icon/color
     *   - แอปมือถือ: config_provider.dart อ่านชุดเดียวกัน
     *   - utils/web3.js: buildAddChainParams ใช้ตอนกด "เพิ่มเครือข่าย" เข้ากระเป๋า
     *
     * เดิมค่าพวกนี้มาจาก config/chains.php ซึ่งแก้ได้ด้วยการ deploy เท่านั้น
     * ตอนนี้มาจากฐานข้อมูล แอดมินจึงแก้ได้จริงจากหน้า /admin/chains
     * แต่ "หน้าตา" ของ JSON ยังเหมือนเดิมทุกคีย์ ผู้อ่านทั้งสามฝั่งจึงไม่ต้องแก้อะไร
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        // rpc ตัวหลักมาก่อน แล้วต่อด้วยตัวสำรอง — ตัดค่าว่างและตัวซ้ำออก
        $rpc = array_values(array_unique(array_filter(
            array_merge([$this->rpc_url], $this->rpc_fallbacks ?? []),
            fn ($url) => filled($url)
        )));

        return [
            'name' => $this->name,
            'shortName' => $this->short_name ?: $this->symbol,
            'chainId' => $this->chain_id,
            'networkId' => $this->network_id ?? $this->chain_id,
            'rpc' => $rpc,
            'explorer' => $this->explorer_url,
            'nativeCurrency' => [
                'name' => $this->native_currency_name,
                'symbol' => $this->native_currency_symbol,
                'decimals' => (int) $this->native_currency_decimals,
            ],
            'icon' => $this->logo_url,
            'color' => $this->color,
            'enabled' => (bool) $this->is_active,
            'status' => $this->status,
            'gasless' => (bool) $this->gasless,
            'blockTime' => $this->block_time,
            'consensus' => $this->consensus,
            'isTestnet' => (bool) $this->is_testnet,
            'blockConfirmations' => (int) $this->block_confirmations,
        ];
    }
}
