<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Back in a minute</title>
    <style>
        :root {
            --bg: #f9fafb;
            --card: #ffffff;
            --text: #111827;
            --muted: #4b5563;
            --border: #e5e7eb;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0b0f14;
                --card: #151b23;
                --text: #f3f4f6;
                --muted: #9ca3af;
                --border: #2a323d;
            }
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: var(--bg);
            color: var(--text);
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
        }

        main {
            width: 100%;
            max-width: 28rem;
            padding: 2rem;
            text-align: center;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 0.75rem;
        }

        h1 {
            margin: 0 0 0.75rem;
            font-size: 1.5rem;
        }

        p {
            margin: 0;
            color: var(--muted);
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <main>
        <h1>Back in a minute</h1>
        <p>We're updating {{ config('app.name') }}. This page reloads by itself.</p>
    </main>
</body>
</html>
