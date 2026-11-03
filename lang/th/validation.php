<?php

/**
 * TPIX TRADE — ข้อความ validation ภาษาไทย
 * แปลจากไฟล์ภาษาอังกฤษเริ่มต้นของ Laravel (ครบทุกคีย์ คง placeholder เดิมไว้)
 * Developed by Xman Studio.
 */

return [
    'accepted' => 'ต้องยอมรับ :attribute',
    'accepted_if' => 'ต้องยอมรับ :attribute เมื่อ :other เป็น :value',
    'active_url' => ':attribute ต้องเป็น URL ที่ถูกต้อง',
    'after' => ':attribute ต้องเป็นวันที่หลัง :date',
    'after_or_equal' => ':attribute ต้องเป็นวันที่ตั้งแต่ :date เป็นต้นไป',
    'alpha' => ':attribute ต้องประกอบด้วยตัวอักษรเท่านั้น',
    'alpha_dash' => ':attribute ต้องประกอบด้วยตัวอักษร ตัวเลข ขีดกลาง และขีดล่างเท่านั้น',
    'alpha_num' => ':attribute ต้องประกอบด้วยตัวอักษรและตัวเลขเท่านั้น',
    'any_of' => ':attribute ไม่ถูกต้อง',
    'array' => ':attribute ต้องเป็นอาร์เรย์',
    'ascii' => ':attribute ต้องประกอบด้วยตัวอักษร ตัวเลข และสัญลักษณ์แบบไบต์เดียวเท่านั้น',
    'before' => ':attribute ต้องเป็นวันที่ก่อน :date',
    'before_or_equal' => ':attribute ต้องเป็นวันที่ไม่เกิน :date',
    'between' => [
        'array' => ':attribute ต้องมีจำนวนระหว่าง :min ถึง :max รายการ',
        'file' => ':attribute ต้องมีขนาดระหว่าง :min ถึง :max กิโลไบต์',
        'numeric' => ':attribute ต้องมีค่าระหว่าง :min ถึง :max',
        'string' => ':attribute ต้องมีความยาวระหว่าง :min ถึง :max ตัวอักษร',
    ],
    'boolean' => ':attribute ต้องเป็นจริงหรือเท็จ',
    'can' => ':attribute มีค่าที่ไม่ได้รับอนุญาต',
    'confirmed' => 'การยืนยัน :attribute ไม่ตรงกัน',
    'contains' => ':attribute ขาดค่าที่จำเป็น',
    'current_password' => 'รหัสผ่านไม่ถูกต้อง',
    'date' => ':attribute ต้องเป็นวันที่ที่ถูกต้อง',
    'date_equals' => ':attribute ต้องเป็นวันที่ :date',
    'date_format' => ':attribute ต้องตรงกับรูปแบบ :format',
    'decimal' => ':attribute ต้องมีทศนิยม :decimal ตำแหน่ง',
    'declined' => 'ต้องปฏิเสธ :attribute',
    'declined_if' => 'ต้องปฏิเสธ :attribute เมื่อ :other เป็น :value',
    'different' => ':attribute และ :other ต้องไม่เหมือนกัน',
    'digits' => ':attribute ต้องเป็นตัวเลข :digits หลัก',
    'digits_between' => ':attribute ต้องเป็นตัวเลขระหว่าง :min ถึง :max หลัก',
    'dimensions' => ':attribute มีขนาดรูปภาพไม่ถูกต้อง',
    'distinct' => ':attribute มีค่าซ้ำกัน',
    'doesnt_contain' => ':attribute ต้องไม่มีค่าใดค่าหนึ่งต่อไปนี้: :values',
    'doesnt_end_with' => ':attribute ต้องไม่ลงท้ายด้วยค่าใดค่าหนึ่งต่อไปนี้: :values',
    'doesnt_start_with' => ':attribute ต้องไม่ขึ้นต้นด้วยค่าใดค่าหนึ่งต่อไปนี้: :values',
    'email' => ':attribute ต้องเป็นที่อยู่อีเมลที่ถูกต้อง',
    'encoding' => ':attribute ต้องเข้ารหัสแบบ :encoding',
    'ends_with' => ':attribute ต้องลงท้ายด้วยค่าใดค่าหนึ่งต่อไปนี้: :values',
    'enum' => ':attribute ที่เลือกไม่ถูกต้อง',
    'exists' => ':attribute ที่เลือกไม่ถูกต้อง',
    'extensions' => ':attribute ต้องมีนามสกุลไฟล์อย่างใดอย่างหนึ่งต่อไปนี้: :values',
    'file' => ':attribute ต้องเป็นไฟล์',
    'filled' => ':attribute ต้องมีค่า',
    'gt' => [
        'array' => ':attribute ต้องมีมากกว่า :value รายการ',
        'file' => ':attribute ต้องมีขนาดมากกว่า :value กิโลไบต์',
        'numeric' => ':attribute ต้องมีค่ามากกว่า :value',
        'string' => ':attribute ต้องมีความยาวมากกว่า :value ตัวอักษร',
    ],
    'gte' => [
        'array' => ':attribute ต้องมีอย่างน้อย :value รายการ',
        'file' => ':attribute ต้องมีขนาดอย่างน้อย :value กิโลไบต์',
        'numeric' => ':attribute ต้องมีค่ามากกว่าหรือเท่ากับ :value',
        'string' => ':attribute ต้องมีความยาวอย่างน้อย :value ตัวอักษร',
    ],
    'hex_color' => ':attribute ต้องเป็นรหัสสีฐานสิบหกที่ถูกต้อง',
    'image' => ':attribute ต้องเป็นรูปภาพ',
    'in' => ':attribute ที่เลือกไม่ถูกต้อง',
    'in_array' => ':attribute ต้องมีอยู่ใน :other',
    'in_array_keys' => ':attribute ต้องมีคีย์อย่างน้อยหนึ่งคีย์ต่อไปนี้: :values',
    'integer' => ':attribute ต้องเป็นจำนวนเต็ม',
    'ip' => ':attribute ต้องเป็นที่อยู่ IP ที่ถูกต้อง',
    'ipv4' => ':attribute ต้องเป็นที่อยู่ IPv4 ที่ถูกต้อง',
    'ipv6' => ':attribute ต้องเป็นที่อยู่ IPv6 ที่ถูกต้อง',
    'json' => ':attribute ต้องเป็นสตริง JSON ที่ถูกต้อง',
    'list' => ':attribute ต้องเป็นลิสต์',
    'lowercase' => ':attribute ต้องเป็นตัวพิมพ์เล็ก',
    'lt' => [
        'array' => ':attribute ต้องมีน้อยกว่า :value รายการ',
        'file' => ':attribute ต้องมีขนาดน้อยกว่า :value กิโลไบต์',
        'numeric' => ':attribute ต้องมีค่าน้อยกว่า :value',
        'string' => ':attribute ต้องมีความยาวน้อยกว่า :value ตัวอักษร',
    ],
    'lte' => [
        'array' => ':attribute ต้องมีไม่เกิน :value รายการ',
        'file' => ':attribute ต้องมีขนาดไม่เกิน :value กิโลไบต์',
        'numeric' => ':attribute ต้องมีค่าน้อยกว่าหรือเท่ากับ :value',
        'string' => ':attribute ต้องมีความยาวไม่เกิน :value ตัวอักษร',
    ],
    'mac_address' => ':attribute ต้องเป็นที่อยู่ MAC ที่ถูกต้อง',
    'max' => [
        'array' => ':attribute ต้องมีไม่เกิน :max รายการ',
        'file' => ':attribute ต้องมีขนาดไม่เกิน :max กิโลไบต์',
        'numeric' => ':attribute ต้องมีค่าไม่เกิน :max',
        'string' => ':attribute ต้องมีความยาวไม่เกิน :max ตัวอักษร',
    ],
    'max_digits' => ':attribute ต้องมีไม่เกิน :max หลัก',
    'mimes' => ':attribute ต้องเป็นไฟล์ประเภท: :values',
    'mimetypes' => ':attribute ต้องเป็นไฟล์ประเภท: :values',
    'min' => [
        'array' => ':attribute ต้องมีอย่างน้อย :min รายการ',
        'file' => ':attribute ต้องมีขนาดอย่างน้อย :min กิโลไบต์',
        'numeric' => ':attribute ต้องมีค่าอย่างน้อย :min',
        'string' => ':attribute ต้องมีความยาวอย่างน้อย :min ตัวอักษร',
    ],
    'min_digits' => ':attribute ต้องมีอย่างน้อย :min หลัก',
    'missing' => 'ต้องไม่มี :attribute',
    'missing_if' => 'ต้องไม่มี :attribute เมื่อ :other เป็น :value',
    'missing_unless' => 'ต้องไม่มี :attribute เว้นแต่ :other เป็น :value',
    'missing_with' => 'ต้องไม่มี :attribute เมื่อมี :values',
    'missing_with_all' => 'ต้องไม่มี :attribute เมื่อมี :values ทั้งหมด',
    'multiple_of' => ':attribute ต้องเป็นผลคูณของ :value',
    'not_in' => ':attribute ที่เลือกไม่ถูกต้อง',
    'not_regex' => 'รูปแบบของ :attribute ไม่ถูกต้อง',
    'numeric' => ':attribute ต้องเป็นตัวเลข',
    'password' => [
        'letters' => ':attribute ต้องมีตัวอักษรอย่างน้อยหนึ่งตัว',
        'mixed' => ':attribute ต้องมีตัวพิมพ์ใหญ่และตัวพิมพ์เล็กอย่างน้อยอย่างละหนึ่งตัว',
        'numbers' => ':attribute ต้องมีตัวเลขอย่างน้อยหนึ่งตัว',
        'symbols' => ':attribute ต้องมีสัญลักษณ์อย่างน้อยหนึ่งตัว',
        'uncompromised' => ':attribute นี้เคยปรากฏในข้อมูลที่รั่วไหล กรุณาเลือก :attribute อื่น',
    ],
    'present' => 'ต้องมี :attribute',
    'present_if' => 'ต้องมี :attribute เมื่อ :other เป็น :value',
    'present_unless' => 'ต้องมี :attribute เว้นแต่ :other เป็น :value',
    'present_with' => 'ต้องมี :attribute เมื่อมี :values',
    'present_with_all' => 'ต้องมี :attribute เมื่อมี :values ทั้งหมด',
    'prohibited' => 'ไม่อนุญาตให้ระบุ :attribute',
    'prohibited_if' => 'ไม่อนุญาตให้ระบุ :attribute เมื่อ :other เป็น :value',
    'prohibited_if_accepted' => 'ไม่อนุญาตให้ระบุ :attribute เมื่อยอมรับ :other',
    'prohibited_if_declined' => 'ไม่อนุญาตให้ระบุ :attribute เมื่อปฏิเสธ :other',
    'prohibited_unless' => 'ไม่อนุญาตให้ระบุ :attribute เว้นแต่ :other อยู่ใน :values',
    'prohibits' => ':attribute ห้ามระบุพร้อมกับ :other',
    'regex' => 'รูปแบบของ :attribute ไม่ถูกต้อง',
    'required' => 'กรุณาระบุ :attribute',
    'required_array_keys' => ':attribute ต้องมีรายการสำหรับ: :values',
    'required_if' => 'กรุณาระบุ :attribute เมื่อ :other เป็น :value',
    'required_if_accepted' => 'กรุณาระบุ :attribute เมื่อยอมรับ :other',
    'required_if_declined' => 'กรุณาระบุ :attribute เมื่อปฏิเสธ :other',
    'required_unless' => 'กรุณาระบุ :attribute เว้นแต่ :other อยู่ใน :values',
    'required_with' => 'กรุณาระบุ :attribute เมื่อมี :values',
    'required_with_all' => 'กรุณาระบุ :attribute เมื่อมี :values ทั้งหมด',
    'required_without' => 'กรุณาระบุ :attribute เมื่อไม่มี :values',
    'required_without_all' => 'กรุณาระบุ :attribute เมื่อไม่มี :values เลย',
    'same' => ':attribute ต้องตรงกับ :other',
    'size' => [
        'array' => ':attribute ต้องมี :size รายการ',
        'file' => ':attribute ต้องมีขนาด :size กิโลไบต์',
        'numeric' => ':attribute ต้องมีค่าเท่ากับ :size',
        'string' => ':attribute ต้องมีความยาว :size ตัวอักษร',
    ],
    'starts_with' => ':attribute ต้องขึ้นต้นด้วยค่าใดค่าหนึ่งต่อไปนี้: :values',
    'string' => ':attribute ต้องเป็นข้อความ',
    'timezone' => ':attribute ต้องเป็นเขตเวลาที่ถูกต้อง',
    'unique' => ':attribute นี้ถูกใช้ไปแล้ว',
    'uploaded' => 'อัปโหลด :attribute ไม่สำเร็จ',
    'uppercase' => ':attribute ต้องเป็นตัวพิมพ์ใหญ่',
    'url' => ':attribute ต้องเป็น URL ที่ถูกต้อง',
    'ulid' => ':attribute ต้องเป็น ULID ที่ถูกต้อง',
    'uuid' => ':attribute ต้องเป็น UUID ที่ถูกต้อง',

    /*
    |--------------------------------------------------------------------------
    | ข้อความ validation แบบกำหนดเอง
    |--------------------------------------------------------------------------
    |
    | กำหนดข้อความเฉพาะฟิลด์ได้ที่นี่ โดยใช้รูปแบบ "attribute.rule"
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | ชื่อฟิลด์แบบกำหนดเอง
    |--------------------------------------------------------------------------
    |
    | ใช้แทนค่า :attribute ด้วยชื่อที่อ่านง่าย เช่น "อีเมล" แทน "email"
    |
    */

    'attributes' => [],
];
