<section id="reports" aria-labelledby="reports-heading" class="landing-section bg-gray-50 dark:bg-gray-900/50">
    <div class="landing-container">
        <div class="max-w-3xl">
            <span class="landing-section-label dark:bg-primary-500/10 dark:text-primary-300">
                <span class="h-1.5 w-1.5 rounded-full bg-primary-500" aria-hidden="true"></span>
                Reports
            </span>

            <h2 id="reports-heading" class="mt-4 text-[2rem] font-extrabold leading-tight text-gray-900 sm:text-[2.15rem] dark:text-white">Numbers you can <span class="text-primary-600 dark:text-primary-400">act on</span></h2>

            <p class="mt-3 text-sm leading-7 text-gray-600 sm:text-[15px] dark:text-gray-300">Open the dashboard to see your day; open Reports to see the trend. Filter both by period and, in a team, by owner.</p>
        </div>

        <div data-reports-visual class="mt-10 rounded-[2rem] border border-gray-200/70 bg-white p-2 shadow-sm shadow-primary-950/5 sm:p-3 dark:border-white/10 dark:bg-gray-950">
            <img src="{{ asset('images/landing/dashboard-1440.webp') }}" srcset="{{ asset('images/landing/dashboard-1440.webp') }} 1440w, {{ asset('images/landing/dashboard-2880.webp') }} 2880w" sizes="(min-width: 1280px) 1248px, 100vw" width="1440" height="900" alt="The dashboard, with the key figures compared with the previous period." loading="lazy" decoding="async" class="block h-auto w-full rounded-[1.5rem]">
        </div>

        <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <x-landing.feature-card title="My day">
                    <x-slot:icon><svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z"/></svg></x-slot:icon>
                    Your overdue tasks, tasks due today and your deals closing this week.
                </x-landing.feature-card>
                <x-landing.feature-card title="Key figures">
                    <x-slot:icon><svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0-1 3m8.5-3 1 3m0 0 .5 1.5m-.5-1.5h-9.5m0 0-.5 1.5m.75-9 3-3 2.148 2.148A12.061 12.061 0 0 1 16.5 7.605"/></svg></x-slot:icon>
                    Won revenue, deals won, win rate, new deals, cash collected and new contacts, each compared with the previous period, with a small trend line.
                </x-landing.feature-card>
                <x-landing.feature-card title="What you're owed">
                    <x-slot:icon><svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z"/></svg></x-slot:icon>
                    Unpaid balances by how late they are, and the oldest overdue invoices.
                </x-landing.feature-card>
                <x-landing.feature-card title="Revenue and forecast">
                    <x-slot:icon><svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/></svg></x-slot:icon>
                    Cash collected and won value by month for the last 12 months, and open deals by expected close month, weighted by probability.
                </x-landing.feature-card>
                <x-landing.feature-card title="Reports">
                    <x-slot:icon><svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M9 16.5v.75m3-3v3M15 12v5.25m-4.5-15H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg></x-slot:icon>
                    Win rate and lost reasons, stage conversion, time in stage and sales velocity, revenue by client, leads and won deals by source, and a team activity leaderboard.
                </x-landing.feature-card>
                <x-landing.feature-card title="Export for your accountant">
                    <x-slot:icon><svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg></x-slot:icon>
                    Admins can export the team leaderboard and revenue by client to Excel or CSV.
                </x-landing.feature-card>
        </div>
    </div>
</section>
