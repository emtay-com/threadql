<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>ThreadQL Installed</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
        <style>
            :root {
                color-scheme: light;
                --page-bg-start: #0a2e4d;
                --page-bg-end: #4bbac4;
                --card-bg: rgba(255, 255, 255, 0.96);
                --card-border: rgba(226, 232, 240, 0.95);
                --text-main: #0a2e4d;
                --text-muted: #64748b;
                --accent: #4bbac4;
                --accent-strong: #0a2e4d;
                --shadow: 0 24px 60px rgba(10, 46, 77, 0.22);
            }

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                min-height: 100vh;
                display: grid;
                place-items: center;
                padding: 32px 20px;
                font-family: "Instrument Sans", ui-sans-serif, system-ui, sans-serif;
                color: var(--text-main);
                background:
                    radial-gradient(circle at top left, rgba(255, 255, 255, 0.16), transparent 28%),
                    radial-gradient(circle at bottom right, rgba(255, 255, 255, 0.12), transparent 22%),
                    linear-gradient(135deg, var(--page-bg-start) 0%, var(--page-bg-end) 100%);
            }

            main {
                width: min(760px, 100%);
                padding: clamp(28px, 5vw, 56px);
                border: 1px solid var(--card-border);
                border-radius: 24px;
                background: var(--card-bg);
                box-shadow: var(--shadow);
                text-align: center;
            }

            .logo {
                width: 20vw;
                min-width: 180px;
                max-width: 320px;
                height: auto;
                display: block;
                margin: 0 auto 28px;
            }

            h1 {
                margin: 0;
                font-size: clamp(2.25rem, 5vw, 4rem);
                line-height: 1.02;
                letter-spacing: -0.04em;
            }

            p {
                margin: 18px auto 0;
                max-width: 36rem;
                font-size: clamp(1.05rem, 2vw, 1.25rem);
                line-height: 1.65;
                color: var(--text-muted);
            }

            .version {
                margin-top: 22px;
                display: inline-block;
                padding: 10px 16px;
                border-radius: 999px;
                background: rgba(75, 186, 196, 0.14);
                color: var(--accent-strong);
                font-size: 0.95rem;
                font-weight: 600;
                line-height: 1.2;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .panel-link {
                margin-top: 30px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 14px 22px;
                border-radius: 999px;
                background: linear-gradient(90deg, var(--accent) 0%, var(--accent-strong) 100%);
                color: #ffffff;
                font-size: 1rem;
                font-weight: 600;
                line-height: 1;
                text-decoration: none;
                box-shadow: 0 14px 30px rgba(10, 46, 77, 0.18);
                transition: box-shadow 0.15s ease, transform 0.15s ease, filter 0.15s ease;
            }

            .panel-link:hover,
            .panel-link:focus-visible {
                transform: translateY(-1px);
                filter: brightness(1.03);
                box-shadow: 0 18px 36px rgba(10, 46, 77, 0.24);
            }

            .hint {
                margin-top: 16px;
                font-size: 0.98rem;
            }

            @media (max-width: 640px) {
                body {
                    padding: 20px 14px;
                }

                main {
                    border-radius: 22px;
                }

                .logo {
                    width: 48vw;
                    min-width: 160px;
                }
            }
        </style>
    </head>
    <body>
        <main>
            <img class="logo" src="{{ asset('images/threadql_logo.png') }}" alt="ThreadQL logo">
            <h1>ThreadQL is installed and ready to use.</h1>
            <span class="version">Version {{ $version }}</span>
            <p>
                Your ThreadQL instance is up and available. You can administer ThreadQL from the panel.
            </p>
            <a class="panel-link" href="{{ url('/panel') }}">Open the admin panel at /panel</a>
            <p class="hint">
                Use <strong>/panel</strong> to manage tenants, data sources, settings, and other administrative tasks.
            </p>
        </main>
    </body>
</html>
