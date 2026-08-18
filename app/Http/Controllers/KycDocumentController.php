<?php

namespace App\Http\Controllers;

use App\Models\KycDocument;
use App\Models\KycDocumentView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * TPIX TRADE — ทางเดียวที่จะเปิดดูเอกสารยืนยันตัวตนได้.
 *
 * ไฟล์อยู่บน disk `kyc` ซึ่งไม่มี URL สาธารณะโดยตั้งใจ (ดู config/filesystems.php)
 * ที่นี่คือประตูเดียว — จึงเป็นที่เดียวที่ต้องตรวจสิทธิ์และบันทึกการเข้าถึง
 *
 * ⚠️ ห้ามเพิ่มทางลัดอื่นให้เข้าถึงไฟล์เหล่านี้ ไม่ว่าจะสะดวกแค่ไหน
 *    ทุกทางที่เพิ่มคือทางที่ต้องตรวจสิทธิ์ให้ถูกอีกรอบ และเป็นทางที่ประวัติการเข้าถึงหาย
 *
 * Developed by Xman Studio.
 */
class KycDocumentController extends Controller
{
    /**
     * แอดมินเปิดดูเพื่อตรวจ — บันทึกทุกครั้ง.
     */
    public function admin(Request $request, KycDocument $document): StreamedResponse
    {
        $admin = Auth::guard('admin')->user();

        abort_if(! $admin, 403);

        // บันทึก "ก่อน" ส่งไฟล์
        //
        // ⚠️ ลำดับนี้สำคัญ — บันทึกหลังส่งไฟล์แล้วถ้าเขียนฐานข้อมูลพลาด
        //    จะกลายเป็นว่าเอกสารถูกเปิดดูไปแล้วโดยไม่มีร่องรอย
        //    ยอมให้บันทึกเกินจริงตอนดาวน์โหลดหลุดกลางคัน ดีกว่าบันทึกขาด
        KycDocumentView::create([
            'kyc_document_id' => $document->id,
            'admin_user_id' => $admin->id,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'viewed_at' => now(),
        ]);

        return $this->stream($document);
    }

    /**
     * เจ้าของเปิดดูเอกสารตัวเอง — ให้ตรวจทานว่าส่งรูปถูกใบ.
     */
    public function owner(Request $request, KycDocument $document): StreamedResponse
    {
        $document->loadMissing('submission');

        abort_if(
            $document->submission?->user_id !== Auth::id(),
            403
        );

        return $this->stream($document);
    }

    /**
     * ส่งไฟล์ออกไปแบบไม่ให้เบราว์เซอร์หรือ CDN เก็บไว้.
     */
    private function stream(KycDocument $document): StreamedResponse
    {
        abort_if($document->isPurged(), 410, 'เอกสารนี้ถูกลบตามคำขอหรือครบกำหนดเก็บแล้ว');

        $disk = Storage::disk($document->disk);

        abort_if(! $disk->exists($document->path), 404);

        return $disk->response($document->path, null, [
            // นี่คือรูปบัตรประชาชน — ห้ามให้ proxy หรือ CDN ระหว่างทางเก็บสำเนาไว้
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            // กันเบราว์เซอร์เดาชนิดไฟล์เอง เผื่อมีอะไรหลุดด่านตรวจตอนอัปโหลดมาได้
            'X-Content-Type-Options' => 'nosniff',
            'Content-Type' => $document->mime ?: 'application/octet-stream',
            // เปิดในแท็บใหม่ได้ แต่ชื่อไฟล์ที่ผู้ใช้ตั้งมาไม่ต้องไปโผล่
            'Content-Disposition' => 'inline; filename="document"',
        ]);
    }
}
