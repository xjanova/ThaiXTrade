<?php

namespace App\Services\TokenList;

use App\Models\FactoryToken;
use App\Models\SiteSetting;
use App\Models\Token;
use App\Services\Bridge\Abi;
use App\Services\ChainResolver;
use App\Services\ContractRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * รายการโทเคนสาธารณะของ TPIX Chain ตามมาตรฐาน Uniswap Token List.
 *
 *   GET /tokenlist.json · GET /api/v1/tokens/list
 *   schema: https://uniswap.org/tokenlist.schema.json
 *
 * ใครใช้: cron บนเครื่อง explorer (Blockscout) ดึงไปตั้งไอคอนโทเคน และ TPIX Wallet
 * ⚠️ ทั้งสองฝั่งถือว่าเหรียญในรายการนี้ "ผ่านการรับรอง" — อะไรที่ไม่ได้ผ่านมือแอดมินต้องไม่หลุดเข้ามา
 *
 * แหล่งข้อมูล (ซ้ำที่อยู่เดียวกัน = ตัวแรกชนะ ลำดับตามนี้):
 *   1. WTPIX + USDT_TPIX จาก ContractRegistry (ที่อยู่ผ่านด่านลายนิ้วมือ bytecode มาแล้ว)
 *   2. แถว tokens บนเชน TPIX ที่ is_active และ is_verified (แอดมินรับรอง)
 *   3. factory_tokens บนเชน 4289 ที่ deployed และ is_listed (แอดมินกดลิสต์)
 *
 * กันปลอม:
 *   - ที่อยู่ที่เป็นเหรียญจาก Token Factory แต่ยังไม่ลิสต์/ถูกเลิกลิสต์ → ตัดทิ้งจากทุกแหล่ง
 *     (dex:sync เคยสร้างแถว tokens ตอนที่เหรียญยังลิสต์อยู่ แถวนั้นไม่หายไปเองตอนเลิกลิสต์)
 *   - ที่อยู่ placeholder ของแถวดัชนี (12 ไบต์แรกเป็นศูนย์) และที่อยู่ศูนย์ (TPIX native) ไม่เข้า
 *   - TPIX native ไม่ใช่ ERC-20 → ไม่อยู่ใน tokens แต่บอกไว้ใน extensions.nativeCurrency
 *
 * เวอร์ชัน: major 1 · minor = จำนวนเหรียญ · patch = ตัวเลขจาก sha256 ของเนื้อหา
 * timestamp = เวลาที่เนื้อหาชุดนี้ปรากฏครั้งแรก (เก็บใน SiteSetting) → สร้างใหม่ทุก 5 นาที
 * แต่ถ้าเนื้อหาไม่เปลี่ยน body/ETag เท่าเดิม ผู้ดึงได้ 304
 *
 * Developed by Xman Studio.
 */
class TokenListService
{
    public const CACHE_KEY = 'tokenlist:tpix:v1';

    public const CACHE_TTL = 300;

    public const LIST_NAME = 'TPIX Chain';

    public const KEYWORDS = ['tpix', 'tpix chain', 'tpix trade'];

    public const STATE_GROUP = 'tokenlist';

    public const STATE_KEY = 'state';

    public const LOGO_PATH = '/images/brand/tpix-coin.png';

    /** ข้อมูลสำรองของสัญญาในทะเบียน — ใช้เมื่อยังไม่มีแถว tokens ของที่อยู่นั้น */
    private const REGISTRY = [
        // ตรงกับ contracts/tpix-chain/WTPIX.sol
        'wtpix' => ['name' => 'Wrapped TPIX', 'symbol' => 'WTPIX', 'decimals' => 18, 'logo' => self::LOGO_PATH],
        // decimals 6 ตาม BridgePublicInfo / dex:sync
        'usdt_tpix' => ['name' => 'Tether USD (TPIX)', 'symbol' => 'USDT', 'decimals' => 6, 'logo' => null],
    ];

    public function __construct(
        private ContractRegistry $contracts,
        private ChainResolver $chains,
    ) {}

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function chainId(): int
    {
        return (int) config('blockchain.tpix_chain_id', 4289);
    }

    /**
     * ตัวที่ controller ส่งออก — แคช 5 นาที.
     *
     * @return array{body:string, etag:string, last_modified:int}
     */
    public function payload(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => $this->render());
    }

    /**
     * @return array{body:string, etag:string, last_modified:int}
     */
    public function render(): array
    {
        $list = $this->build();
        $body = json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return [
            'body' => $body,
            'etag' => substr(hash('sha256', $body), 0, 32),
            'last_modified' => CarbonImmutable::parse($list['timestamp'])->getTimestamp(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $tokens = $this->tokens();
        $logo = $this->absoluteLogo(self::LOGO_PATH);

        $content = array_filter([
            'logoURI' => $logo,
            'keywords' => self::KEYWORDS,
            'tokens' => $tokens,
            'extensions' => [
                'nativeCurrency' => array_filter([
                    'chainId' => $this->chainId(),
                    'name' => 'TPIX',
                    'symbol' => 'TPIX',
                    'decimals' => 18,
                    'logoURI' => $logo,
                ], fn ($v) => $v !== null),
            ],
        ], fn ($v) => $v !== null);

        $hash = hash('sha256', json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return array_merge([
            'name' => self::LIST_NAME,
            'timestamp' => $this->timestampFor($hash)->format('Y-m-d\TH:i:s.v\Z'),
            'version' => [
                'major' => 1,
                'minor' => count($tokens),
                'patch' => (int) hexdec(substr($hash, 0, 7)),
            ],
        ], $content);
    }

    /**
     * รายการเหรียญที่ผ่านทุกด่านแล้ว เรียงตาม symbol แล้วตามที่อยู่ (ไม่ขึ้นกับ id/sort_order ในฐานข้อมูล).
     *
     * @return list<array{chainId:int, address:string, name:string, symbol:string, decimals:int, logoURI?:string}>
     */
    public function tokens(): array
    {
        $chainId = $this->chainId();
        $blockedFactory = $this->blockedFactoryAddresses($chainId);

        /** @var array<string, list<array{name:string, symbol:string, decimals:int, logo:?string}>> $candidates */
        $candidates = [];
        $add = function (?string $address, string $name, string $symbol, mixed $decimals, ?string $logo) use (&$candidates, $blockedFactory): void {
            $key = $this->addressKey($address);
            if ($key === null || isset($blockedFactory[$key])) {
                return;
            }

            $candidates[$key][] = [
                'name' => $name,
                'symbol' => $symbol,
                'decimals' => is_numeric($decimals) ? (int) $decimals : -1,
                'logo' => $logo,
            ];
        };

        $chain = $this->chains->resolve($chainId);
        $coreRows = $chain
            ? Token::query()->where('chain_id', $chain->id)->get(['id', 'name', 'symbol', 'contract_address', 'decimals', 'logo', 'is_active', 'is_verified'])
            : collect();
        $coreByAddress = $coreRows->keyBy(fn (Token $t) => strtolower((string) $t->contract_address));

        // 1. ทะเบียนสัญญา
        foreach (self::REGISTRY as $key => $fallback) {
            $address = $this->contracts->address($key);
            if ($address === null) {
                continue;
            }

            $row = $coreByAddress->get(strtolower($address));
            $add(
                $address,
                (string) ($row->name ?? $fallback['name']),
                (string) ($row->symbol ?? $fallback['symbol']),
                $row->decimals ?? $fallback['decimals'],
                $row?->logo ?: $fallback['logo'],
            );
        }

        // 2. แถว tokens ที่แอดมินรับรอง
        foreach ($coreRows->sortBy('id') as $row) {
            if ($row->is_active && $row->is_verified) {
                $add($row->contract_address, (string) $row->name, (string) $row->symbol, $row->decimals, $row->logo);
            }
        }

        // 3. เหรียญจาก Token Factory ที่ลิสต์แล้ว
        FactoryToken::query()
            ->where('chain_id', $chainId)
            ->where('status', 'deployed')
            ->where('is_listed', true)
            ->whereNotNull('contract_address')
            ->orderBy('id')
            ->get(['id', 'name', 'symbol', 'decimals', 'contract_address', 'logo_url'])
            ->each(fn (FactoryToken $t) => $add($t->contract_address, (string) $t->name, (string) $t->symbol, $t->decimals, $t->logo_url));

        $out = [];
        foreach ($candidates as $address => $sources) {
            $entry = $this->entry($chainId, $address, $sources);
            if ($entry !== null) {
                $out[] = $entry;
            }
        }

        usort($out, fn (array $a, array $b) => [strtoupper($a['symbol']), strtolower($a['address'])]
            <=> [strtoupper($b['symbol']), strtolower($b['address'])]);

        return $out;
    }

    /**
     * ค่าโลโก้ในฐานข้อมูล → URL https เต็ม (null = ไม่ใส่ logoURI).
     *
     * - https://… ภายนอก → ใช้ตามนั้น
     * - /storage/… หรือ path เปล่า (ไฟล์ที่อัปโหลด) → ต้องมีไฟล์อยู่จริงบนดิสก์ public
     * - /… อื่น → ไฟล์ใน public_html ต้องมีอยู่จริง
     * ต่อด้วย APP_URL เสมอ (ไม่ใช้ host ของคำขอ — แคชก้อนเดียวใช้กับทุกคน)
     */
    public function absoluteLogo(?string $logo): ?string
    {
        $logo = trim((string) $logo);
        if ($logo === '' || strlen($logo) > 500) {
            return null;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $logo) || str_starts_with($logo, '//')) {
            return $this->isHttpsUrl($logo) ? $logo : null;
        }

        $diskPath = TokenLogoStorage::diskPath($logo);
        if ($diskPath !== null) {
            if (! $this->safePath($diskPath) || ! Storage::disk(TokenLogoStorage::DISK)->exists($diskPath)) {
                return null;
            }

            return $this->appUrl('storage/'.$diskPath);
        }

        $publicPath = ltrim($logo, '/');
        if (! $this->safePath($publicPath) || ! is_file(public_path($publicPath))) {
            return null;
        }

        return $this->appUrl($publicPath);
    }

    /**
     * @param  list<array{name:string, symbol:string, decimals:int, logo:?string}>  $sources
     * @return array{chainId:int, address:string, name:string, symbol:string, decimals:int, logoURI?:string}|null
     */
    private function entry(int $chainId, string $address, array $sources): ?array
    {
        $primary = $sources[0];
        $name = trim($primary['name']);
        $symbol = trim($primary['symbol']);
        $decimals = $primary['decimals'];

        $valid = $decimals >= 0 && $decimals <= 36
            && $name !== '' && mb_strlen($name) <= 60 && preg_match('/^[ \S]+$/u', $name)
            && $symbol !== '' && mb_strlen($symbol) <= 20 && preg_match('/^\S+$/u', $symbol);

        if (! $valid) {
            Log::warning('tokenlist: ข้ามเหรียญที่ข้อมูลไม่ผ่านมาตรฐาน', [
                'address' => $address,
                'symbol' => $symbol,
                'decimals' => $decimals,
            ]);

            return null;
        }

        $entry = [
            'chainId' => $chainId,
            'address' => Abi::checksum($address),
            'name' => $name,
            'symbol' => $symbol,
            'decimals' => $decimals,
        ];

        foreach ($sources as $source) {
            $logo = $this->absoluteLogo($source['logo']);
            if ($logo !== null) {
                $entry['logoURI'] = $logo;
                break;
            }
        }

        return $entry;
    }

    /**
     * ที่อยู่ของเหรียญจาก Token Factory บนเชนนี้ที่ "ห้ามเข้ารายการ" จากทุกแหล่ง
     * = มีแถวในโรงงาน แต่ไม่มีแถวไหนของที่อยู่นั้นที่ deployed และลิสต์แล้ว.
     *
     * @return array<string, true>
     */
    private function blockedFactoryAddresses(int $chainId): array
    {
        $listed = [];
        $all = [];

        FactoryToken::query()
            ->where('chain_id', $chainId)
            ->whereNotNull('contract_address')
            ->get(['contract_address', 'status', 'is_listed'])
            ->each(function (FactoryToken $t) use (&$listed, &$all) {
                $key = strtolower((string) $t->contract_address);
                $all[$key] = true;
                if ($t->status === 'deployed' && $t->is_listed) {
                    $listed[$key] = true;
                }
            });

        return array_diff_key($all, $listed);
    }

    /** ที่อยู่ที่ใช้ได้ → ตัวพิมพ์เล็ก · ที่อยู่ศูนย์/placeholder/รูปแบบผิด → null */
    private function addressKey(?string $address): ?string
    {
        $address = strtolower(trim((string) $address));

        if (! preg_match('/^0x[0-9a-f]{40}$/', $address)) {
            return null;
        }

        // 12 ไบต์แรกเป็นศูนย์ = ที่อยู่ศูนย์ (TPIX native), precompile หรือ placeholder ของแถวดัชนี
        // สัญญาจริง (CREATE/CREATE2) ไม่มีทางได้ที่อยู่แบบนี้
        if (str_starts_with($address, '0x'.str_repeat('0', 24))) {
            return null;
        }

        return $address;
    }

    /**
     * เวลาที่เนื้อหาชุดนี้เกิดขึ้นครั้งแรก — เนื้อหาเดิม = เวลาเดิม (body/ETag ไม่ขยับเอง).
     */
    private function timestampFor(string $hash): CarbonImmutable
    {
        $state = SiteSetting::get(self::STATE_GROUP, self::STATE_KEY);

        if (is_array($state) && ($state['hash'] ?? null) === $hash && is_string($state['timestamp'] ?? null)) {
            try {
                return CarbonImmutable::parse($state['timestamp'])->utc();
            } catch (\Throwable) {
                // ค่าเสีย — เขียนใหม่ด้านล่าง
            }
        }

        $now = CarbonImmutable::now('UTC')->startOfSecond();

        try {
            SiteSetting::set(self::STATE_GROUP, self::STATE_KEY, json_encode([
                'hash' => $hash,
                'timestamp' => $now->toIso8601ZuluString(),
            ]), 'json');
        } catch (\Throwable $e) {
            Log::warning('tokenlist: บันทึกเวอร์ชันไม่สำเร็จ', ['error' => $e->getMessage()]);
        }

        return $now;
    }

    private function appUrl(string $path): ?string
    {
        $url = rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');

        return $this->isHttpsUrl($url) ? $url : null;
    }

    private function isHttpsUrl(string $url): bool
    {
        return str_starts_with(strtolower($url), 'https://')
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && ! preg_match('/[\s"\'<>\\\\]/', $url);
    }

    private function safePath(string $path): bool
    {
        return $path !== ''
            && ! str_contains($path, '..')
            && (bool) preg_match('#^[A-Za-z0-9._/-]+$#', $path);
    }
}
