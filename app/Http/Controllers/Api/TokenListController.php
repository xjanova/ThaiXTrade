<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TokenList\TokenListService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /tokenlist.json และ GET /api/v1/tokens/list — รายการโทเคน TPIX Chain (Uniswap Token List).
 *
 * เปิดให้ทุก origin อ่านได้ (Access-Control-Allow-Origin: *) เฉพาะสอง route นี้
 * ไม่มีข้อมูลส่วนตัว ไม่ใช้คุกกี้ — HandleCors ของ /api/* ถูกข้ามสำหรับ path นี้ใน AppServiceProvider
 * (ไม่งั้นมันเขียน header ทับเป็น origin เดียวของเว็บ + credentials)
 *
 * Developed by Xman Studio.
 */
class TokenListController extends Controller
{
    public const MAX_AGE = 300;

    public function __construct(private TokenListService $tokenList) {}

    public function show(Request $request): Response
    {
        $payload = $this->tokenList->payload();

        $response = response($payload['body'], 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Access-Control-Allow-Origin' => '*',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $response->setEtag($payload['etag']);
        $response->setLastModified((new \DateTimeImmutable())->setTimestamp($payload['last_modified']));
        $response->setPublic();
        $response->setMaxAge(self::MAX_AGE);

        if ($this->notModified($request, $payload)) {
            $response->setNotModified();
        }

        return $response;
    }

    /**
     * เทียบแบบ weak ตาม RFC 9110 — Cloudflare แปลง ETag เป็น W/"…" ตอนบีบอัด
     * และ Apache mod_deflate ต่อท้าย "-gzip" ให้ ถ้าเทียบตรง ๆ จะไม่เคยได้ 304 บนเครื่องจริง.
     *
     * @param  array{etag:string, last_modified:int}  $payload
     */
    private function notModified(Request $request, array $payload): bool
    {
        $ifNoneMatch = $request->headers->get('If-None-Match');

        if ($ifNoneMatch !== null) {
            foreach (explode(',', $ifNoneMatch) as $tag) {
                $tag = trim($tag);
                if ($tag === '*') {
                    return true;
                }

                $tag = preg_replace('#^W/#i', '', $tag);
                $tag = trim((string) $tag, '"');
                $tag = (string) preg_replace('/-(gzip|br|zstd|deflate)$/i', '', $tag);

                if ($tag !== '' && hash_equals($payload['etag'], $tag)) {
                    return true;
                }
            }

            // มี If-None-Match แต่ไม่ตรง → ห้ามดู If-Modified-Since (RFC 9110 §13.1.3)
            return false;
        }

        $ifModifiedSince = $request->headers->get('If-Modified-Since');
        if ($ifModifiedSince === null) {
            return false;
        }

        $since = strtotime($ifModifiedSince);

        return $since !== false && $payload['last_modified'] <= $since;
    }
}
