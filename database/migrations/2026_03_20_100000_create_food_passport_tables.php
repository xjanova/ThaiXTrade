<?php

/**
 * FoodPassport — ระบบตรวจสอบที่มาอาหารบน Blockchain
 * Tables: food_products, food_traces, iot_devices, food_certificates
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ═══════════════════════════════════════════
        //  IoT Devices — อุปกรณ์ที่ส่งข้อมูลเข้า chain
        // ═══════════════════════════════════════════
        Schema::create('iot_devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 100)->unique();         // รหัสอุปกรณ์ (TPIX-IOT-001)
            $table->string('name');                              // ชื่อ: เช่น "Sensor ฟาร์มข้าว สุรินทร์"
            $table->enum('type', [
                'temperature', 'humidity', 'gps',
                'camera', 'weight', 'ph', 'multi',
            ]);
            $table->string('wallet_address', 42)->nullable();   // wallet ของ device บน TPIX Chain
            $table->string('owner_address', 42);                // wallet ของเจ้าของ
            $table->string('location')->nullable();              // GPS coordinates
            $table->string('firmware_version', 20)->nullable();
            $table->enum('status', ['active', 'inactive', 'maintenance'])->default('active');
            $table->json('config')->nullable();                  // การตั้งค่าเฉพาะ
            $table->timestamp('last_ping_at')->nullable();
            $table->timestamps();

            $table->index('owner_address');
            $table->index('status');
        });

        // ═══════════════════════════════════════════
        //  Food Products — ผลิตภัณฑ์อาหาร
        // ═══════════════════════════════════════════
        Schema::create('food_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('chain_product_id')->nullable();  // Product ID on chain
            $table->string('name');                              // ข้าวหอมมะลิ ทุ่งกุลาร้องไห้
            $table->string('category', 50);                      // fruit, vegetable, meat, etc.
            $table->string('origin');                            // สุรินทร์, ประเทศไทย
            $table->string('producer_address', 42);              // wallet เกษตรกร
            $table->string('producer_name')->nullable();         // ชื่อเกษตรกร/ฟาร์ม
            $table->string('batch_number', 50)->nullable();      // Lot/Batch เช่น BATCH-2026-001
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            $table->decimal('weight_kg', 10, 2)->nullable();     // น้ำหนัก (กก.)
            $table->date('harvest_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('tx_hash', 66)->nullable();           // TX ที่ register บน chain
            $table->enum('status', [
                'registered', 'in_transit', 'at_storage',
                'at_retail', 'certified', 'expired',
            ])->default('registered');
            $table->json('metadata')->nullable();                // ข้อมูลเสริม
            $table->timestamps();

            $table->index('producer_address');
            $table->index('category');
            $table->index('status');
            $table->index('batch_number');
        });

        // ═══════════════════════════════════════════
        //  Food Traces — บันทึกการเดินทาง (IoT data)
        // ═══════════════════════════════════════════
        Schema::create('food_traces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('food_product_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('chain_trace_id')->nullable();   // Trace ID on chain
            $table->foreignId('iot_device_id')->nullable()->constrained()->onDelete('set null');
            $table->string('recorder_address', 42);              // wallet ของผู้บันทึก/device
            $table->enum('stage', [
                'farm', 'processing', 'storage', 'transport', 'retail',
            ]);
            $table->string('location')->nullable();              // GPS
            $table->decimal('temperature', 8, 2)->nullable();    // °C
            $table->decimal('humidity', 8, 2)->nullable();       // %
            $table->decimal('weight_kg', 10, 2)->nullable();
            $table->decimal('ph_level', 5, 2)->nullable();
            $table->json('sensor_data')->nullable();             // raw sensor data
            $table->string('image_url')->nullable();             // ภาพจาก camera sensor
            $table->text('notes')->nullable();
            $table->string('tx_hash', 66)->nullable();           // TX ที่บันทึกบน chain
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['food_product_id', 'stage']);
            $table->index('recorder_address');
        });

        // ═══════════════════════════════════════════
        //  Food Certificates — NFT ใบรับรอง
        // ═══════════════════════════════════════════
        Schema::create('food_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('food_product_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('token_id');              // NFT Token ID on chain
            $table->string('owner_address', 42);                 // wallet เจ้าของ NFT
            $table->string('contract_address', 42);              // FoodPassportNFT contract
            $table->string('token_uri')->nullable();             // IPFS/API URI
            $table->string('tx_hash', 66)->nullable();           // TX ที่ mint
            $table->string('qr_code_url')->nullable();           // QR Code สำหรับสแกน
            $table->json('certificate_data')->nullable();        // summary ข้อมูลใบรับรอง
            $table->enum('status', ['active', 'revoked', 'expired'])->default('active');
            $table->timestamps();

            $table->unique(['token_id', 'contract_address']);
            $table->index('owner_address');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_certificates');
        Schema::dropIfExists('food_traces');
        Schema::dropIfExists('food_products');
        Schema::dropIfExists('iot_devices');
    }
};
