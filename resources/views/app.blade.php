<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name', 'TPIX TRADE') }}</title>

    <!-- Meta Tags -->
    <meta name="description" content="TPIX TRADE - Decentralized Exchange Platform by Xman Studio. Trade securely from your own wallet.">
    <meta name="keywords" content="DEX, decentralized exchange, crypto trading, web3, blockchain, TPIX TRADE">
    <meta name="author" content="Xman Studio">

    <!-- Open Graph -->
    <meta property="og:title" content="{{ config('app.name', 'TPIX TRADE') }}">
    <meta property="og:description" content="TPIX TRADE - Decentralized Exchange Platform. Trade securely from your own wallet.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ config('app.url') }}">
    <meta property="og:image" content="{{ asset('images/og-image.png') }}">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ config('app.name', 'TPIX TRADE') }}">
    <meta name="twitter:description" content="TPIX TRADE - Decentralized Exchange Platform. Trade securely from your own wallet.">

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

    <!-- Preconnect to external resources -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <!-- Theme Color -->
    <meta name="theme-color" content="#020617">
    <meta name="msapplication-navbutton-color" content="#020617">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

    <!-- PWA -->
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="application-name" content="TPIX TRADE">
    <meta name="apple-mobile-web-app-title" content="TPIX TRADE">

    <!-- Default Locale จาก admin settings — frontend ใช้เป็น fallback ถ้า user ยังไม่เลือก -->
    <meta name="default-locale" content="{{ \App\Models\Language::where('is_default', true)->value('code') ?? 'th' }}">

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead

    <!-- Google Translate removed: caused visual glitches (empty floating box) -->
</head>
<body class="font-sans antialiased bg-dark-950 text-white">
    @inertia

    <!-- Noscript Fallback -->
    <noscript>
        <div style="padding: 20px; text-align: center; background: #020617; color: #fff;">
            <h1>JavaScript Required</h1>
            <p>TPIX TRADE requires JavaScript to run. Please enable JavaScript in your browser settings.</p>
        </div>
    </noscript>
</body>
</html>
