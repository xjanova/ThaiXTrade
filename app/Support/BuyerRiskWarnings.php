<?php

namespace App\Support;

/**
 * คำเตือนความเสี่ยงสำหรับผู้ซื้อเหรียญลูก — ชุดเดียวที่ทุกหน้าใช้ร่วมกัน
 * (ไวท์เปเปอร์มาตรฐาน หน้าโครงการสาธารณะ และหน้าขาย /launchpad ที่กำลังทำ).
 *
 * เจ้าของสั่ง 2026-11-06: คำเตือนไทย/อังกฤษก่อนปุ่มซื้อ — ศึกษาก่อนลงทุน, ราคาลงได้เมื่อ vesting ปลด,
 * โครงการล้มได้, ไม่รับประกันผลตอบแทน · ไม่มี escrow
 *
 * ห้ามเขียนว่าแพลตฟอร์มอายัด ยึด หรือหยุดเหรียญบนเชนได้ — สัญญาไม่มีสิทธิ์นั้น
 *
 * Developed by Xman Studio.
 */
final class BuyerRiskWarnings
{
    /** @return list<array{id: string, th: string, en: string}> */
    public static function all(): array
    {
        return [
            [
                'id' => 'study',
                'th' => 'ศึกษาข้อมูลโครงการ ทีมงาน และข้อมูลบนเชนด้วยตนเองก่อนซื้อ ใช้เฉพาะเงินที่เสียได้ทั้งหมด',
                'en' => 'Study the project, the team and the on-chain facts yourself before buying. Only use money you can afford to lose entirely.',
            ],
            [
                'id' => 'price',
                'th' => 'ราคาเหรียญขึ้นลงได้มากและอาจลดลงจนไม่มีมูลค่า โดยเฉพาะเมื่อเหรียญที่ล็อกไว้ (vesting) ถูกปลดหรือเมื่อ LP ครบกำหนดล็อก',
                'en' => 'The token price can swing sharply and may fall to zero, especially when locked (vesting) tokens unlock or when the LP lock ends.',
            ],
            [
                'id' => 'failure',
                'th' => 'โครงการอาจล่าช้า เปลี่ยนแผน หรือล้มเหลวได้ และอาจไม่มีผู้ซื้อเมื่อคุณต้องการขาย',
                'en' => 'The project may be delayed, change its plans or fail, and there may be no buyer when you want to sell.',
            ],
            [
                'id' => 'no-guarantee',
                'th' => 'แพลตฟอร์มและผู้สร้างไม่รับประกันราคา สภาพคล่อง หรือผลตอบแทนใดๆ ข้อมูลในเอกสารนี้ไม่ใช่คำแนะนำการลงทุน',
                'en' => 'Neither the platform nor the creator guarantees any price, liquidity or return. Nothing here is investment advice.',
            ],
            [
                'id' => 'no-escrow',
                'th' => 'แพลตฟอร์มไม่ได้ถือเงินแทนผู้ซื้อหรือผู้สร้าง (ไม่มี escrow) เงินไหลตามสัญญาอัจฉริยะโดยตรง และธุรกรรมบนบล็อกเชนย้อนกลับไม่ได้',
                'en' => 'The platform does not hold funds for buyers or creators (no escrow). Money moves by smart contract directly, and blockchain transactions cannot be reversed.',
            ],
            [
                'id' => 'no-freeze',
                'th' => 'แพลตฟอร์มซ่อนหรือถอดหน้าโครงการออกจากเว็บไซต์ได้ แต่ไม่สามารถอายัด ยึด หรือหยุดเหรียญ พูล หรือ vesting บนเชนได้',
                'en' => 'The platform can hide or remove a project from its website, but it cannot freeze, seize or stop tokens, pools or vesting on chain.',
            ],
        ];
    }
}
