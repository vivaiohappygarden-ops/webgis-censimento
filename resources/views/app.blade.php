<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#15803d">
    <link rel="manifest" href="/manifest.webmanifest">
    <title inertia>{{ config('app.name', 'WebGIS Censimento') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="antialiased bg-gray-50 text-gray-900">
    @inertia
</body>
</html>
