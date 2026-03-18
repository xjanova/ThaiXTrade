<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * TPIX TRADE — Banner Model
 * จัดการป้ายโฆษณา: รูปภาพ, Google AdSense, Custom HTML
 * Developed by Xman Studio.
 */
class Banner extends Model
{
    use HasFactory, SoftDeletes;

    // ฟิลด์ที่อนุญาตให้ mass assign
    protected $fillable = [
        'title',
        'type',
        'image_url',
        'link_url',
        'target',
        'ad_code',
        'placement',
        'is_active',
        'sort_order',
        'start_at',
        'end_at',
        'click_count',
        'view_count',
    ];

    // Type casting
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'click_count' => 'integer',
            'view_count' => 'integer',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
        ];
    }

    // ประเภท banner ที่รองรับ
    public const TYPES = [
        'image' => 'รูปภาพ + ลิงก์',
        'google_adsense' => 'Google AdSense',
        'html' => 'Custom HTML',
    ];

    // ตำแหน่งที่แสดงบนเว็บ
    public const PLACEMENTS = [
        'trade_top' => 'หน้าเทรด — ด้านบน',
        'trade_sidebar' => 'หน้าเทรด — Sidebar',
        'home_hero' => 'หน้าแรก — Hero Section',
        'home_bottom' => 'หน้าแรก — ด้านล่าง',
        'explorer_header' => 'Explorer — Header',
        'explorer_sidebar' => 'Explorer — Sidebar',
        'all_pages_top' => 'ทุกหน้า — ด้านบน',
    ];

    /**
     * Scope: เฉพาะ banner ที่ active
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: เฉพาะ banner สำหรับ placement ที่ระบุ
     */
    public function scopeForPlacement($query, string $placement)
    {
        return $query->where('placement', $placement);
    }

    /**
     * Scope: เฉพาะ banner ที่อยู่ในช่วงเวลาแสดง
     */
    public function scopeScheduled($query)
    {
        $now = now();

        return $query->where(function ($q) use ($now) {
            $q->whereNull('start_at')->orWhere('start_at', '<=', $now);
        })->where(function ($q) use ($now) {
            $q->whereNull('end_at')->orWhere('end_at', '>=', $now);
        });
    }

    /**
     * Scope: เรียงตาม sort_order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * เพิ่มจำนวน views
     */
    public function incrementViews(): void
    {
        $this->increment('view_count');
    }

    /**
     * เพิ่มจำนวน clicks
     */
    public function incrementClicks(): void
    {
        $this->increment('click_count');
    }

    /**
     * ตรวจสอบว่า banner กำลังแสดงอยู่หรือไม่
     */
    public function getIsVisibleAttribute(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();
        if ($this->start_at && $this->start_at->isAfter($now)) {
            return false;
        }
        if ($this->end_at && $this->end_at->isBefore($now)) {
            return false;
        }

        return true;
    }
}
