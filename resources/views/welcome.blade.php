<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,600,700" rel="stylesheet" />

        <style>
            :root {
                color-scheme: light;
            }

            *, *::before, *::after {
                box-sizing: border-box;
                margin: 0;
            }

            body {
                font-family: 'Inter', ui-sans-serif, system-ui, sans-serif;
                -webkit-font-smoothing: antialiased;
                -moz-osx-font-smoothing: grayscale;
                background: linear-gradient(135deg, #1e1e2e 0%, #181825 50%, #11111b 100%);
                color: #cdd6f4;
                min-height: 100vh;
                display: flex;
            }

            .container {
                max-width: 720px;
                margin: auto;
                padding: 2rem;
            }

            .logo {
                display: flex;
                justify-content: center;
                margin-bottom: 2rem;
            }

            .logo svg {
                width: 72px;
                height: 72px;
                color: #f9322c;
                filter: drop-shadow(0 0 24px rgba(249, 50, 44, 0.4));
            }

            h1 {
                font-size: 2.5rem;
                font-weight: 700;
                text-align: center;
                color: #ffffff;
                margin-bottom: 0.75rem;
            }

            .subtitle {
                text-align: center;
                color: #a6adc8;
                font-size: 1.05rem;
                line-height: 1.6;
                margin-bottom: 2.5rem;
            }

            .version {
                text-align: center;
                margin-bottom: 2.5rem;
            }

            .version span {
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                background: rgba(205, 214, 244, 0.08);
                border: 1px solid rgba(205, 214, 244, 0.12);
                border-radius: 9999px;
                padding: 0.35rem 1rem;
                font-size: 0.85rem;
                color: #a6adc8;
            }

            .version .dot {
                width: 6px;
                height: 6px;
                border-radius: 50%;
                background: #a6e3a1;
                display: inline-block;
            }

            .links {
                display: flex;
                justify-content: center;
                gap: 0.75rem;
                flex-wrap: wrap;
            }

            .links a {
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                background: rgba(205, 214, 244, 0.08);
                border: 1px solid rgba(205, 214, 244, 0.12);
                border-radius: 0.75rem;
                padding: 0.75rem 1.25rem;
                color: #cdd6f4;
                text-decoration: none;
                font-size: 0.95rem;
                font-weight: 500;
                transition: all 0.15s ease;
            }

            .links a:hover {
                background: rgba(205, 214, 244, 0.14);
                border-color: rgba(205, 214, 244, 0.24);
                color: #ffffff;
            }

            .links a svg {
                width: 18px;
                height: 18px;
                flex-shrink: 0;
            }

            footer {
                text-align: center;
                margin-top: 3rem;
                color: #585b70;
                font-size: 0.85rem;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="logo">
                <svg viewBox="0 0 50 52" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M49.626 11.564a.809.809 0 0 1 .028.209v10.972a.8.8 0 0 1-.402.694l-9.209 5.302V39.25c0 .286-.15.55-.4.694L20.42 51.01c-.044.025-.092.041-.14.058-.018.006-.035.017-.054.022a.805.805 0 0 1-.41 0c-.022-.006-.042-.018-.063-.026-.044-.016-.09-.03-.132-.056L.402 39.944A.801.801 0 0 1 0 39.25V12.75c0-.286.15-.55.402-.694L19.819.058a.789.789 0 0 1 .402-.058c.238.028.455.12.638.272l.02.014L33.64 8.16a.8.8 0 0 1 .03 1.383L14.71 19.848a.8.8 0 0 1-.401.108.795.795 0 0 1-.402-.108L6.11 15.17a.402.402 0 0 0-.6.347v12.19a.401.401 0 0 0 .2.347l6.804 3.916a.401.401 0 0 0 .6-.347v-4.86a.4.4 0 0 1 .6-.347l2.804 1.615a.8.8 0 0 1 .401.694v4.86c0 .572-.298 1.102-.8 1.39l-6.804 3.916a.8.8 0 0 1-.801 0L.4 34.847A.801.801 0 0 1 0 34.153V15.847c0-.286.15-.55.402-.694L20.218.058a.825.825 0 0 1 .807.004l9.209 5.302a.8.8 0 0 1 .006 1.386L18.65 12.6a.402.402 0 0 0 0 .694l6.003 3.458a.804.804 0 0 1 .402.695v4.754l9.209-5.302a.8.8 0 0 1 .807-.004l6.003 3.458a.402.402 0 0 0 .6-.347V9.44a.402.402 0 0 0-.201-.347l-2.803-1.615a.402.402 0 0 1-.201-.347v-2.37c0-.277.143-.534.371-.675l.076.003a.79.79 0 0 1 .418.104l8.21 4.728a.8.8 0 0 1 .402.694v5.307a.401.401 0 0 0 .6.347l2.804-1.615a.8.8 0 0 0 .402-.694v-2.37a.4.4 0 0 1 .6-.347l2.804 1.615Z" fill="currentColor"/>
                </svg>
            </div>

            <h1>Let's get started</h1>

            <p class="subtitle">
                Laravel has an incredibly rich ecosystem.<br>
                We suggest starting with the following.
            </p>

            <div class="version">
                <span>
                    <span class="dot"></span>
                    Laravel v{{ Illuminate\Foundation\Application::VERSION }} (PHP v{{ PHP_VERSION }})
                </span>
            </div>

            <div class="links">
                <a href="https://laravel.com/docs">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                    </svg>
                    Documentation
                </a>
                <a href="https://laracasts.com">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Laracasts
                </a>
                <a href="https://forge.laravel.com">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>
                    </svg>
                    Deploy now
                </a>
            </div>

            <footer>
                {{ config('app.name', 'Semanur') }} &mdash; Laravel v{{ Illuminate\Foundation\Application::VERSION }}
            </footer>
        </div>
    </body>
</html>
