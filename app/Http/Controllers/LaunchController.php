<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

/**
 * LaunchController — หน้า TPIX Bonding Curve Sale
 *
 * เป็น passthrough ไปยัง Vue page — state ทั้งหมดอ่าน on-chain ที่ frontend
 * (ผ่าน useBondingCurve composable + JsonRpcProvider)
 *
 * Developed by Xman Studio.
 */
class LaunchController extends Controller
{
    public function index()
    {
        return Inertia::render('Launch');
    }
}
