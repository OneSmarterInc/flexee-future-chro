<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · The Future CHRO</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f1f5f9; color: #0f172a; }
        header { background: #1f3a5f; color: #fff; padding: 14px 20px; font-weight: 700; font-size: 18px; }
        main { max-width: 34rem; margin: 24px auto; padding: 0 16px; }
        .card { background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 20px; }
        h1 { font-size: 22px; margin: 0 0 6px; }
        p, li, dd, dt { line-height: 1.5; }
        .muted { color: #64748b; font-size: 14px; }
        dl { display: grid; grid-template-columns: auto 1fr; gap: 4px 12px; font-size: 14px; }
        dd { margin: 0; overflow-wrap: anywhere; }
        .error { border-color: #fca5a5; background: #fef2f2; color: #991b1b; }
        button { width: 100%; padding: 10px; border-radius: 6px; font-size: 16px; font-weight: 600; cursor: pointer; margin-top: 8px; }
        .primary { background: #1f3a5f; color: #fff; border: 0; }
        .ghost { background: #fff; color: #0f172a; border: 1px solid #cbd5e1; }
        a { color: #1f3a5f; }
    </style>
</head>
<body>
    <header>The Future CHRO</header>
    <main>@yield('body')</main>
</body>
</html>
