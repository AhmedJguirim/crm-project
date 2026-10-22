<section id="facts" aria-labelledby="facts-heading" class="landing-section">
    <div class="landing-container">
        <div class="relative overflow-hidden rounded-[2rem] bg-linear-to-bl from-primary-800 via-primary-700 to-primary-900 px-4 py-7 text-white shadow-2xl shadow-primary-950/20 sm:px-7 sm:py-10 lg:rounded-[2.1rem] lg:px-10 lg:py-11">
            <div aria-hidden="true" class="pointer-events-none absolute inset-0">
                <div class="absolute -top-16 start-8 h-40 w-40 rounded-full bg-white/10 blur-3xl"></div>
                <div class="absolute bottom-0 end-0 h-56 w-56 translate-x-1/4 translate-y-1/4 rounded-full bg-primary-300/20 blur-3xl"></div>
                <div class="absolute inset-x-0 top-0 h-px bg-white/20"></div>
            </div>

            <div class="relative grid gap-4 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)] lg:items-end lg:gap-6">
                <div>
                    <span class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1 text-[11px] font-medium tracking-[0.12em] text-white/90 uppercase backdrop-blur-sm">
                        <span class="h-1.5 w-1.5 rounded-full bg-primary-200" aria-hidden="true"></span>
                        Built in
                    </span>

                    <h2 id="facts-heading" class="mt-4 text-[1.7rem] font-extrabold leading-tight sm:text-[2.15rem] lg:text-[2.35rem]">Ready the day you sign up</h2>
                </div>

                <div class="rounded-[1.25rem] border border-white/12 bg-white/8 p-4 backdrop-blur-sm sm:rounded-[1.5rem] sm:p-5">
                    <p class="text-sm leading-6 text-white/90 sm:text-[15px] sm:leading-7">Every new workspace comes with these. Nothing to install, nothing to configure first.</p>
                </div>
            </div>

            <div class="relative mt-6 grid grid-cols-1 gap-3 sm:mt-8 sm:grid-cols-2 xl:grid-cols-4">
                <x-landing.fact-tile index="01" value="6" label="Report tabs" detail="Sales results, Conversion, Velocity, Money, Sources, Team activity" />
                <x-landing.fact-tile index="02" value="4" label="Roles" detail="Owner, Admin, Member, Viewer" />
                <x-landing.fact-tile index="03" value="5" label="Currencies" detail="USD, EUR, GBP, CAD, AUD" />
                <x-landing.fact-tile index="04" value="6" label="Pipeline stages ready on day one" detail="Lead, Discovery, Proposal Sent, Negotiating, Won, Lost" />
            </div>
        </div>
    </div>
</section>
