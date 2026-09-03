<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\BugReportRelayController;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * TPIX TRADE — หน้ารายงานบั๊กในหลังบ้าน (อ่านจากระบบกลางของ xman studio).
 *
 * ที่เก็บจริงอยู่ที่ระบบกลาง (แอปทุกตัวในบ้านส่งเข้าที่เดียว) หน้านี้ดึงเฉพาะ
 * ผลิตภัณฑ์ของ TPIX มาแสดงให้ทีมงานเห็นในที่เดียวกับหน้าอื่นๆ ของหลังบ้าน
 * พร้อม breadcrumb/สภาพแอปที่ตัวรายงานแนบมา — อ่านแล้วรู้ว่าก่อนพังเกิดอะไร
 *
 * แคช 60 วิต่อผลิตภัณฑ์ — เปิดหน้าซ้ำไม่ยิงเซิร์ฟเวอร์กลางถี่ · ปุ่มรีเฟรชล้างแคช
 *
 * Developed by Xman Studio.
 */
class BugReportAdminController extends Controller
{
    private const CACHE_TTL = 60;

    private const PER_PRODUCT = 50;

    public function index(Request $request): InertiaResponse
    {
        $products = BugReportRelayController::PRODUCTS;
        $selected = (string) $request->get('product', 'all');
        $selected = in_array($selected, $products, true) ? $selected : 'all';
        $targets = $selected === 'all' ? $products : [$selected];

        $reports = [];
        $errors = [];

        foreach ($targets as $product) {
            $rows = Cache::remember("admin:bug-reports:{$product}", self::CACHE_TTL, fn () => $this->fetch($product));

            if ($rows === null) {
                $errors[] = $product;

                continue;
            }

            $reports = array_merge($reports, $rows);
        }

        usort($reports, fn ($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));
        $reports = array_slice($reports, 0, 150);

        $since = now()->subDay()->toIso8601String();

        return Inertia::render('Admin/BugReports/Index', [
            'reports' => $reports,
            'products' => $products,
            'selected' => $selected,
            'fetchErrors' => $errors,
            'centralAdminUrl' => (string) config('services.bug_reports.admin_url'),
            'summary' => [
                'total' => count($reports),
                'last24h' => count(array_filter($reports, fn ($r) => (string) $r['created_at'] >= $since)),
                'crashes' => count(array_filter($reports, fn ($r) => $r['report_type'] === 'crash')),
                'byProduct' => collect($reports)->countBy('product_name')->all(),
            ],
        ]);
    }

    /** ล้างแคชแล้วโหลดใหม่ — ใช้ตอนกำลังไล่บั๊กสดๆ */
    public function refresh(): RedirectResponse
    {
        foreach (BugReportRelayController::PRODUCTS as $product) {
            Cache::forget("admin:bug-reports:{$product}");
        }

        return back();
    }

    /** @return array<int, array<string, mixed>>|null null = ดึงไม่ได้ (เซิร์ฟเวอร์กลางล่ม/ปฏิเสธ) */
    private function fetch(string $product): ?array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) config('services.bug_reports.user_agent'),
                'Accept' => 'application/json',
            ])
                ->timeout(10)
                ->get((string) config('services.bug_reports.endpoint'), [
                    'product_name' => $product,
                    'per_page' => self::PER_PRODUCT,
                ]);

            if (! $response->successful()) {
                Log::warning('bug-report admin: ระบบกลางตอบไม่สำเร็จ', ['product' => $product, 'status' => $response->status()]);

                return null;
            }

            $rows = $response->json('data');

            if (! is_array($rows)) {
                return null;
            }

            return array_values(array_map(fn ($r) => $this->present((array) $r), $rows));
        } catch (\Throwable $e) {
            Log::warning('bug-report admin: ดึงจากระบบกลางไม่ได้', ['product' => $product, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function present(array $r): array
    {
        $metadata = is_array($r['metadata'] ?? null) ? $r['metadata'] : [];

        return [
            'id' => $r['id'] ?? null,
            'product_name' => (string) ($r['product_name'] ?? ''),
            'product_version' => (string) ($r['product_version'] ?? $r['app_version'] ?? ''),
            'report_type' => (string) ($r['report_type'] ?? 'bug'),
            'title' => (string) ($r['title'] ?? ''),
            'description' => (string) ($r['description'] ?? ''),
            'stack_trace' => $r['stack_trace'] ?? null,
            'os_version' => $r['os_version'] ?? null,
            'device_id' => $r['device_id'] ?? null,
            'priority' => $r['priority'] ?? null,
            'severity' => $r['severity'] ?? null,
            'status' => $r['status'] ?? null,
            'breadcrumbs' => is_array($metadata['breadcrumbs'] ?? null) ? array_values($metadata['breadcrumbs']) : [],
            'state' => is_array($metadata['state'] ?? null) ? $metadata['state'] : [],
            'metadata' => collect($metadata)->except(['breadcrumbs', 'state'])->all(),
            'created_at' => (string) ($r['created_at'] ?? ''),
        ];
    }
}
