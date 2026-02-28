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
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
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

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead

    <!-- Google Translate Widget (Free, No API Required) -->
    <script type="text/javascript">
        function googleTranslateElementInit() {
            new google.translate.TranslateElement({
                pageLanguage: 'th',
                includedLanguages: 'en,th,zh-CN,zh-TW,ja,ko,vi,id,ms,hi,ar,ru,es,fr,de,pt,it',
                layout: google.translate.TranslateElement.InlineLayout.SIMPLE,
                autoDisplay: false
            }, 'google_translate_element');
        }
    </script>
    <script type="text/javascript" src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>

    <style>
        /* Hide Google Translate banner */
        .goog-te-banner-frame { display: none !important; }
        body { top: 0 !important; }
        .skiptranslate { display: none !important; }

        /* Style the translate widget */
        #google_translate_element {
            background: rgba(15, 23, 42, 0.9);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 8px 12px;
        }

        .goog-te-gadget {
            font-family: 'Inter', sans-serif !important;
            font-size: 12px !important;
            color: #94a3b8 !important;
        }

        .goog-te-gadget-simple {
            background: transparent !important;
            border: none !important;
        }

        .goog-te-gadget-simple img {
            display: none !important;
        }

        .goog-te-gadget-simple .goog-te-menu-value {
            color: #e2e8f0 !important;
        }

        .goog-te-gadget-simple .goog-te-menu-value span {
            color: #0ea5e9 !important;
        }
    </style>
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
