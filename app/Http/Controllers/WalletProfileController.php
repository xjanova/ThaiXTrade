<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * TPIX TRADE — โปรไฟล์ของผู้ใช้ที่ใช้ "เลขกระเป๋าเป็นไอดี".
 *
 * ทำไมไม่ใช้ /profile เดิม: หน้านั้นอยู่หลัง middleware `auth` ซึ่งคุ้มครองการ
 * เปลี่ยนรหัสผ่าน/อีเมล/อวาตาร์อยู่ ถ้าปลด guard ออกเพื่อให้ผู้ใช้กระเป๋าเข้าได้
 * ก็เท่ากับเปิดช่องให้แก้ข้อมูลบัญชีโดยไม่มี session — แลกไม่คุ้ม
 *
 * หน้านี้จึงเป็นของผู้ใช้กระเป๋าโดยเฉพาะ: ดูที่อยู่ เชน สถานะบอท และเลือกผูก
 * บัญชีอีเมลเพิ่มได้ถ้าต้องการ (ไม่บังคับ)
 *
 * ข้อมูลทั้งหมดดึงฝั่ง client จาก walletStore + API ที่ผูกกับ wallet อยู่แล้ว
 * จึงไม่ต้องส่ง state อะไรจาก server มา
 *
 * Developed by Xman Studio.
 */
class WalletProfileController extends Controller
{
    public function index(): InertiaResponse
    {
        return Inertia::render('WalletProfile');
    }
}
