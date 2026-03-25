<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FoodCertificate;
use App\Models\FoodProduct;
use App\Models\FoodTrace;
use App\Models\IoTDevice;
use App\Services\FoodPassportService;
use App\Services\IoTService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FoodPassportController extends Controller
{
    public function __construct(
        private FoodPassportService $foodPassportService,
        private IoTService $ioTService,
    ) {}

    /**
     * Dashboard — สถิติภาพรวม, สินค้าล่าสุด, alerts.
     */
    public function index(Request $request): Response
    {
        $stats = $this->foodPassportService->getStats();

        // Device stats
        $stats['total_devices'] = IoTDevice::count();
        $stats['active_devices'] = IoTDevice::where('status', 'active')->count();
        $stats['offline_devices'] = IoTDevice::where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('last_ping_at')
                    ->orWhere('last_ping_at', '<', now()->subHours(1));
            })->count();

        // Temperature alerts (last 24h)
        $stats['temp_alerts'] = FoodTrace::where('created_at', '>=', now()->subDay())
            ->whereNotNull('temperature')
            ->where(function ($q) {
                $q->where('temperature', '<', 0)->orWhere('temperature', '>', 40);
            })->count();

        // Recent products with traces
        $products = FoodProduct::with('certificate')
            ->withCount('traces')
            ->orderByDesc('created_at')
            ->paginate(15);

        // Filter
        $status = $request->query('status');
        $category = $request->query('category');
        if ($status) {
            $products = FoodProduct::with('certificate')
                ->withCount('traces')
                ->where('status', $status)
                ->when($category, fn ($q) => $q->where('category', $category))
                ->orderByDesc('created_at')
                ->paginate(15);
        }

        return Inertia::render('Admin/FoodPassport/Index', [
            'stats' => $stats,
            'products' => $products,
            'currentStatus' => $status,
            'currentCategory' => $category,
        ]);
    }

    /**
     * Products — ดูรายละเอียดสินค้า + traces.
     */
    public function showProduct(int $id): Response
    {
        $product = FoodProduct::with(['traces.device', 'certificate'])
            ->withCount('traces')
            ->findOrFail($id);

        $journey = $this->foodPassportService->verifyProduct($id);

        return Inertia::render('Admin/FoodPassport/ProductDetail', [
            'product' => $product,
            'journey' => $journey,
        ]);
    }

    /**
     * Products — อัปเดตสถานะ.
     */
    public function updateProductStatus(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'status' => 'required|in:registered,in_transit,at_storage,at_retail,certified,suspended',
        ]);

        $product = FoodProduct::findOrFail($id);
        $product->update(['status' => $request->input('status')]);

        return back()->with('success', "Product {$product->name} status updated to {$request->input('status')}.");
    }

    /**
     * Products — ลบสินค้า (soft delete style — set status suspended).
     */
    public function suspendProduct(int $id): RedirectResponse
    {
        $product = FoodProduct::findOrFail($id);
        $product->update(['status' => 'suspended']);

        return back()->with('success', "Product {$product->name} suspended.");
    }

    // ═══════════════════════════════════════════
    //  IoT DEVICES
    // ═══════════════════════════════════════════

    /**
     * Devices — รายการ IoT devices ทั้งหมด.
     */
    public function devices(Request $request): Response
    {
        $status = $request->query('status');
        $type = $request->query('type');

        $devices = IoTDevice::withCount('traces')
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->when($type, fn ($q, $t) => $q->where('type', $t))
            ->orderByDesc('last_ping_at')
            ->paginate(20);

        $deviceStats = [
            'total' => IoTDevice::count(),
            'active' => IoTDevice::where('status', 'active')->count(),
            'inactive' => IoTDevice::where('status', 'inactive')->count(),
            'maintenance' => IoTDevice::where('status', 'maintenance')->count(),
            'types' => IoTDevice::selectRaw('type, COUNT(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type'),
        ];

        return Inertia::render('Admin/FoodPassport/Devices', [
            'devices' => $devices,
            'deviceStats' => $deviceStats,
            'currentStatus' => $status,
            'currentType' => $type,
        ]);
    }

    /**
     * Devices — เปลี่ยนสถานะ.
     */
    public function updateDeviceStatus(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'status' => 'required|in:active,inactive,maintenance',
        ]);

        $device = IoTDevice::findOrFail($id);
        $device->update(['status' => $request->input('status')]);

        return back()->with('success', "Device {$device->device_id} status updated.");
    }

    /**
     * Devices — Regenerate API key.
     */
    public function regenerateDeviceKey(int $id): RedirectResponse
    {
        $device = IoTDevice::findOrFail($id);
        $newKey = 'fpk_'.bin2hex(random_bytes(16));

        $device->update([
            'config' => array_merge($device->config ?? [], ['api_key' => $newKey]),
        ]);

        return back()->with('success', "New API key generated for {$device->device_id}.");
    }

    /**
     * Devices — ลบ device.
     */
    public function deleteDevice(int $id): RedirectResponse
    {
        $device = IoTDevice::findOrFail($id);
        $deviceId = $device->device_id;
        $device->delete();

        return back()->with('success', "Device {$deviceId} deleted.");
    }

    // ═══════════════════════════════════════════
    //  CERTIFICATES
    // ═══════════════════════════════════════════

    /**
     * Certificates — ใบรับรอง NFT ทั้งหมด.
     */
    public function certificates(Request $request): Response
    {
        $certificates = FoodCertificate::with('product')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(20);

        $certStats = [
            'total' => FoodCertificate::count(),
            'active' => FoodCertificate::where('status', 'active')->count(),
            'revoked' => FoodCertificate::where('status', 'revoked')->count(),
        ];

        return Inertia::render('Admin/FoodPassport/Certificates', [
            'certificates' => $certificates,
            'certStats' => $certStats,
        ]);
    }

    /**
     * Certificates — revoke ใบรับรอง.
     */
    public function revokeCertificate(Request $request, int $id): RedirectResponse
    {
        $request->validate(['reason' => 'required|string|max:500']);

        $cert = FoodCertificate::findOrFail($id);
        $cert->update([
            'status' => 'revoked',
            'certificate_data' => array_merge($cert->certificate_data ?? [], [
                'revoked_at' => now()->toIso8601String(),
                'revoke_reason' => $request->input('reason'),
            ]),
        ]);

        // ย้ายสถานะสินค้าออกจาก certified
        if ($cert->product) {
            $cert->product->update(['status' => 'registered']);
        }

        return back()->with('success', 'Certificate revoked.');
    }

    // ═══════════════════════════════════════════
    //  ALERTS
    // ═══════════════════════════════════════════

    /**
     * Temperature Alerts — รายการเตือนอุณหภูมิ.
     */
    public function alerts(Request $request): Response
    {
        $minTemp = (float) $request->query('min_temp', 0);
        $maxTemp = (float) $request->query('max_temp', 40);
        $hours = (int) $request->query('hours', 24);

        $violations = FoodTrace::with('product')
            ->where('created_at', '>=', now()->subHours($hours))
            ->whereNotNull('temperature')
            ->where(function ($q) use ($minTemp, $maxTemp) {
                $q->where('temperature', '<', $minTemp)
                    ->orWhere('temperature', '>', $maxTemp);
            })
            ->orderByDesc('recorded_at')
            ->paginate(30);

        return Inertia::render('Admin/FoodPassport/Alerts', [
            'violations' => $violations,
            'threshold' => ['min' => $minTemp, 'max' => $maxTemp],
            'hours' => $hours,
        ]);
    }
}
