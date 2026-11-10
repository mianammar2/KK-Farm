<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#013624">
    <meta name="robots" content="noindex">
    <link rel="icon" href="/brand/favicon-32-v2.png" type="image/png" sizes="32x32">
    <link rel="shortcut icon" href="/favicon.ico?v=2">
    <link rel="apple-touch-icon" href="/brand/apple-touch-icon-v2.png">
    {{-- D-090: installable on iPhone (Share → Add to Home Screen) and Android: full screen, own icon, always the newest version. --}}
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="KK Farms">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    <x-inertia::head />
</head>
<body>
    <x-inertia::app />
</body>
</html>
