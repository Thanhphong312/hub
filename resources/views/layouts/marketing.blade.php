<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('logo.png') }}" />
    <meta name="description" content="@yield('meta_description', 'BullStart — the seller workspace for Etsy listings and order fulfillment. Sync your catalog, fulfill open orders, and post tracking back to Etsy from one place.')" />
    <meta property="og:title" content="@yield('title', 'BullStart')" />
    <meta property="og:description" content="@yield('meta_description', 'The seller workspace for Etsy listings and order fulfillment.')" />
    <meta property="og:type" content="website" />
    <title>@yield('title', 'BullStart')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --orange: #FFA526;
            --orange-hover: #FF8800;
            --orange-light: #FFC565;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #E0E0E0;
            margin: 0;
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
            background:
                radial-gradient(1200px 600px at 15% 0%, rgba(255,165,38,0.40) 0%, rgba(255,165,38,0) 55%),
                radial-gradient(900px 500px at 90% 8%, rgba(255,165,38,0.30) 0%, rgba(255,165,38,0) 60%),
                radial-gradient(700px 700px at 50% 45%, rgba(255,165,38,0.10) 0%, rgba(255,165,38,0) 70%),
                radial-gradient(800px 400px at 85% 75%, rgba(255,136,0,0.18) 0%, rgba(255,136,0,0) 65%),
                linear-gradient(180deg, #0A0A0A 0%, #0D0D0D 30%, #0F0F0F 60%, #0A0A0A 100%);
            background-attachment: fixed;
        }
        /* Orange grid overlay */
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(rgba(255,165,38,0.06) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,165,38,0.06) 1px, transparent 1px);
            background-size: 60px 60px;
            mask-image: radial-gradient(ellipse 90% 70% at 50% 0%, #000 25%, transparent 85%);
            -webkit-mask-image: radial-gradient(ellipse 90% 70% at 50% 0%, #000 25%, transparent 85%);
            pointer-events: none;
            z-index: 0;
        }
        body > * { position: relative; z-index: 1; }

        /* ---- Reusable utilities ---- */
        .glass-nav {
            background: rgba(10,10,10,0.85);
            backdrop-filter: saturate(160%) blur(12px);
            -webkit-backdrop-filter: saturate(160%) blur(12px);
            border-bottom: 1px solid rgba(255,165,38,0.30);
            box-shadow: 0 4px 30px rgba(0,0,0,0.4), 0 0 25px rgba(255,165,38,0.08);
        }
        .card-dark {
            background: #141414;
            border: 1px solid rgba(255,165,38,0.12);
            box-shadow: 0 0 40px rgba(255,165,38,0.05);
        }
        .card-soft {
            background: rgba(255,255,255,0.02);
            border: 1px solid rgba(255,255,255,0.06);
        }
        .btn-glow {
            background: var(--orange);
            color: #1a1208;
            box-shadow: 0 0 15px rgba(255,165,38,0.40), 0 0 30px rgba(255,165,38,0.12);
            transition: all .2s ease;
        }
        .btn-glow:hover { background: var(--orange-hover); color:#fff; transform: translateY(-1px); }
        .btn-ghost {
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.12);
            color: #E0E0E0;
            transition: all .2s ease;
        }
        .btn-ghost:hover { border-color: rgba(255,165,38,0.5); color: #fff; }
        .text-gradient {
            background: linear-gradient(90deg, #FFC565, #FFA526, #FF8800);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .glow-text { text-shadow: 0 0 24px rgba(255,165,38,0.35); }
        .skel { background: linear-gradient(90deg, #1c1c1c 0%, #262626 50%, #1c1c1c 100%); border-radius: 4px; }
        ::selection { background: rgba(255,165,38,0.3); color:#fff; }
        html { scroll-behavior: smooth; }
    </style>
    @yield('head')
</head>
<body>
    @include('partials.marketing-nav')

    @yield('content')

    @include('partials.marketing-footer')
</body>
</html>
