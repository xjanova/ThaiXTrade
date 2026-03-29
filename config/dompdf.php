<?php

return [

    /*
    |--------------------------------------------------------------------------
    | DomPDF Settings for TPIX TRADE
    |--------------------------------------------------------------------------
    |
    | โปรเจคใช้ public_html แทน public
    | ต้องตั้ง public_path ให้ DomPDF resolve ได้ถูกต้อง
    |
    */
    'show_warnings' => false,

    // ใช้ public_html แทน public (สำคัญมาก — DomPDF default ไปหา base_path('public'))
    'public_path' => base_path('public_html'),

    'convert_entities' => true,

    'options' => [
        'font_dir' => storage_path('fonts'),
        'font_cache' => storage_path('fonts'),
        'temp_dir' => sys_get_temp_dir(),
        'chroot' => realpath(base_path()),

        'allowed_protocols' => [
            'data://' => ['rules' => []],
            'file://' => ['rules' => []],
            'http://' => ['rules' => []],
            'https://' => ['rules' => []],
        ],

        'artifactPathValidation' => null,
        'log_output_file' => null,
        'enable_font_subsetting' => true,
        'pdf_backend' => 'CPDF',
        'default_media_type' => 'screen',
        'default_paper_size' => 'a4',
        'default_paper_orientation' => 'portrait',
        'default_font' => 'sans-serif',
        'dpi' => 96,
        'enable_php' => false,
        'enable_javascript' => false,
        'enable_remote' => true,
        'allowed_remote_hosts' => null,
        'font_height_ratio' => 1.1,
        'enable_html5_parser' => true,
    ],

];
