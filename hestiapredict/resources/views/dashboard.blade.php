<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kamoro Hotel - Tableau de bord</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            color-scheme: light;
            --sand-50: #fbf4ea;
            --sand-100: #f4eadc;
            --sand-200: #eadbc8;
            --ink: #231f1b;
            --muted: #75695e;
            --accent: #1f7665;
            --accent-soft: rgba(31, 118, 101, 0.12);
            --line: rgba(68, 52, 39, 0.10);
            --panel: rgba(251, 244, 234, 0.88);
            --panel-strong: rgba(247, 239, 227, 0.96);
        }

        body {
            font-family: "Manrope", sans-serif;
            letter-spacing: 0;
            background:
                radial-gradient(circle at top left, rgba(255, 255, 255, 0.9), transparent 34%),
                radial-gradient(circle at top right, rgba(226, 209, 184, 0.42), transparent 28%),
                linear-gradient(180deg, #f8f0e6 0%, #f5ecdf 48%, #f7f0e8 100%);
            color: var(--ink);
        }

        .display-serif {
            font-family: "Cormorant Garamond", serif;
        }

        .sand-panel {
            background: var(--panel);
            border: 1px solid var(--line);
            box-shadow: 0 18px 44px rgba(78, 62, 48, 0.08);
            backdrop-filter: blur(12px);
        }

        .sand-panel-strong {
            background: var(--panel-strong);
            border: 1px solid rgba(68, 52, 39, 0.12);
            box-shadow: 0 20px 50px rgba(78, 62, 48, 0.1);
        }

        .bento-card {
            border-radius: 30px;
            background: linear-gradient(180deg, rgba(251, 244, 234, 0.98), rgba(247, 238, 228, 0.94));
            border: 1px solid var(--line);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.62), 0 18px 40px rgba(78, 62, 48, 0.08);
        }

        .kpi-value {
            font-family: "Cormorant Garamond", serif;
            font-size: clamp(2.4rem, 3.6vw, 4rem);
            line-height: 0.95;
            font-weight: 600;
            letter-spacing: 0;
        }

        .pill-btn {
            min-height: 46px;
            border-radius: 999px;
            transition: 180ms ease;
            box-shadow: 0 10px 22px rgba(35, 31, 27, 0.06);
        }

        .pill-btn.active {
            background: var(--ink);
            color: #f8f1e8;
            box-shadow: 0 12px 26px rgba(35, 31, 27, 0.16);
        }

        .floating-panel {
            pointer-events: none;
            opacity: 0;
            transform: translateY(12px);
            transition: opacity 180ms ease, transform 180ms ease;
        }

        .floating-panel.open {
            pointer-events: auto;
            opacity: 1;
            transform: translateY(0);
        }

        .table-shell {
            border: 1px solid var(--line);
            border-radius: 28px;
            overflow: hidden;
            background: rgba(255, 251, 247, 0.82);
            box-shadow: 0 14px 34px rgba(78, 62, 48, 0.06);
        }

        .data-table th {
            background: rgba(239, 228, 212, 0.8);
            color: var(--muted);
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .data-table td {
            color: #3e352e;
            vertical-align: middle;
        }

        .data-table tbody tr:hover {
            background: rgba(255, 255, 255, 0.58);
        }

        .icon {
            width: 18px;
            height: 18px;
            stroke-width: 2;
        }

        .tab-content {
            animation: sectionFade 220ms ease;
        }

        @keyframes sectionFade {
            from {
                opacity: 0;
                transform: translateY(8px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 760px) {
            .mobile-card-table table,
            .mobile-card-table thead,
            .mobile-card-table tbody,
            .mobile-card-table th,
            .mobile-card-table td,
            .mobile-card-table tr {
                display: block;
            }

            .mobile-card-table thead {
                display: none;
            }

            .mobile-card-table tr {
                border: 1px solid var(--line);
                border-radius: 24px;
                margin: 0 0 12px;
                background: rgba(255, 251, 247, 0.9);
                overflow: hidden;
            }

            .mobile-card-table td {
                display: flex;
                justify-content: space-between;
                gap: 16px;
                padding: 12px 16px;
                border-bottom: 1px solid rgba(68, 52, 39, 0.08);
            }

            .mobile-card-table td:last-child {
                border-bottom: 0;
            }

            .mobile-card-table td::before {
                content: attr(data-label);
                color: var(--muted);
                font-size: 0.75rem;
                font-weight: 700;
                text-transform: uppercase;
            }
        }
    </style>
</head>
<body class="min-h-screen">
    <div class="min-h-screen">
        <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <section class="grid grid-cols-1 gap-4 xl:grid-cols-12">
                <article class="bento-card col-span-1 overflow-hidden p-6 sm:p-8 xl:col-span-8">
                    <div class="flex flex-col gap-8 xl:flex-row xl:items-start xl:justify-between">
                        <div class="max-w-3xl">
                            <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-[#8f745b]">Analyse et management</p>
                            <h1 class="display-serif mt-3 text-5xl leading-none text-[var(--ink)] sm:text-6xl lg:text-7xl">Kamoro Hotel</h1>
                            <p class="mt-4 max-w-2xl text-sm leading-6 text-[var(--muted)] sm:text-base">
                                Vue éditoriale des revenus, de l'occupation et de la tarification dynamique. L'ensemble reste opérationnel, mais la lecture doit ressembler à un magazine de décoration haut de gamme.
                            </p>
                        </div>

                        <div class="flex flex-col gap-3 sm:flex-row xl:flex-col">
                            <a href="http://localhost:8080" target="_blank" class="pill-btn inline-flex items-center justify-center gap-2 bg-[var(--ink)] px-5 text-sm font-bold text-[#fbf4ea] transition hover:opacity-90">
                                <svg class="icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 4h10a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 18h6"/></svg>
                                Réception
                            </a>
                            <form method="POST" action="{{ route('dashboard.logout') }}">
                                @csrf
                                <button type="submit" class="pill-btn inline-flex items-center justify-center gap-2 bg-white px-5 text-sm font-bold text-[var(--ink)] transition hover:bg-[var(--sand-100)]">
                                    Déconnexion
                                </button>
                            </form>
                            <div class="sand-panel rounded-full px-5 py-3" id="connection-status">
                                <span class="block text-[11px] font-extrabold uppercase tracking-[0.18em] text-[#8f745b]">Tarification dynamique</span>
                                <span class="mt-1 flex items-center gap-2 text-sm font-semibold text-emerald-700" id="connection-text-container">
                                    <span id="connection-dot" class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
                                    <span id="connection-text">Active</span>
                                </span>
                            </div>
                        </div>
                    </div>
                </article>

                <article class="bento-card col-span-1 p-6 xl:col-span-4">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-[#8f745b]">Date de référence</p>
                    <div class="mt-4">
                        <input type="date" id="global-date" onchange="refreshAll()" class="h-12 w-full rounded-full border border-[rgba(68,52,39,0.12)] bg-white/70 px-4 text-sm font-semibold text-[var(--ink)] outline-none transition focus:border-[var(--accent)] focus:ring-2 focus:ring-[rgba(31,118,101,0.12)]">
                    </div>
                    <p class="mt-4 text-sm leading-6 text-[var(--muted)]">Le tableau ajuste l'occupation, les réservations actives et les revenus sur la date sélectionnée.</p>
                </article>

                <article class="bento-card col-span-1 p-6 sm:p-7 xl:col-span-3">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-[#8f745b]">CA officiel</p>
                    <p class="kpi-value mt-4 text-[var(--ink)]" id="stat-ca-official">0 Ar</p>
                    <p class="mt-4 text-sm text-[var(--muted)]">Clients arrivés uniquement.</p>
                </article>

                <article class="bento-card col-span-1 p-6 sm:p-7 xl:col-span-3">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-[#8f745b]">CA en attente</p>
                    <p class="kpi-value mt-4 text-[var(--ink)]" id="stat-ca-pending">0 Ar</p>
                    <p class="mt-4 text-sm text-[var(--muted)]">Réservations non confirmées.</p>
                </article>

                <article class="bento-card col-span-1 p-6 sm:p-7 xl:col-span-3">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-[#8f745b]">Occupation estimée</p>
                    <p class="kpi-value mt-4 text-[var(--ink)]"><span id="stat-rooms-estimated">0</span></p>
                    <p class="mt-4 text-sm text-[var(--muted)]"><span id="stat-rooms-confirmed">0</span> chambres confirmées.</p>
                </article>

                <article class="bento-card col-span-1 p-6 sm:p-7 xl:col-span-3">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-[#8f745b]">CA cumulé officiel</p>
                    <p class="kpi-value mt-4 text-[var(--ink)]" id="stat-ca-total">0 Ar</p>
                    <p class="mt-4 text-sm text-[var(--muted)]" id="ca-period">Période</p>
                </article>

                <aside class="col-span-1 space-y-4 xl:col-span-4">
                    <article class="bento-card p-6">
                        <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-[#8f745b]">RevPAR</p>
                        <p class="display-serif mt-3 text-4xl font-semibold text-[var(--ink)]" id="finance-revpar">0 Ar</p>
                    </article>
                    <article class="bento-card p-6">
                        <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-[#8f745b]">Occupation réelle</p>
                        <p class="display-serif mt-3 text-4xl font-semibold text-[var(--ink)]" id="finance-occ-rate">0%</p>
                    </article>
                    <article class="bento-card p-6">
                        <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-[#8f745b]">Prix moyen</p>
                        <p class="display-serif mt-3 text-4xl font-semibold text-[var(--ink)]" id="finance-adr">0 Ar</p>
                    </article>
                </aside>

                <article class="bento-card col-span-1 p-6 sm:p-7 xl:col-span-12">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                        <div>
                            <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-[#8f745b]">Recherche client</p>
                            <h2 class="display-serif mt-2 text-4xl font-semibold text-[var(--ink)]">Historique complet</h2>
                            <p class="mt-2 text-sm text-[var(--muted)]">Tape le nom ou le prénom d’un client pour voir ses réservations passées, en cours et à venir, ainsi que l’état de la facture.</p>
                        </div>
                        <div class="flex w-full max-w-2xl gap-3">
                            <input
                                id="client-history-query"
                                type="text"
                                placeholder="Nom et prénom du client"
                                class="h-12 flex-1 rounded-full border border-[rgba(68,52,39,0.12)] bg-white/80 px-4 text-sm font-semibold text-[var(--ink)] outline-none transition focus:border-[var(--accent)] focus:ring-2 focus:ring-[rgba(31,118,101,0.12)]"
                                onkeydown="if (event.key === 'Enter') searchClientHistory();"
                            >
                            <button
                                type="button"
                                onclick="searchClientHistory()"
                                class="pill-btn inline-flex items-center justify-center gap-2 bg-[var(--ink)] px-5 text-sm font-bold text-[#fbf4ea] transition hover:opacity-90"
                            >
                                Rechercher
                            </button>
                        </div>
                    </div>
                    <div id="client-history-summary" class="mt-4 text-sm font-semibold text-[var(--muted)]">Aucune recherche lancée.</div>
                    <div class="table-shell mobile-card-table mt-6">
                        <table class="data-table w-full text-left text-sm">
                            <thead>
                                <tr>
                                    <th class="px-5 py-3 cursor-pointer hover:text-[var(--accent)]" onclick="sortClientHistoryByKey('period')">Période</th>
                                    <th class="px-5 py-3 cursor-pointer hover:text-[var(--accent)]" onclick="sortClientHistoryByKey('reference')">Référence</th>
                                    <th class="px-5 py-3 cursor-pointer hover:text-[var(--accent)]" onclick="sortClientHistoryByKey('client_name')">Client</th>
                                    <th class="px-5 py-3 cursor-pointer hover:text-[var(--accent)]" onclick="sortClientHistoryByKey('contact')">Contact</th>
                                    <th class="px-5 py-3">Chambre(s)</th>
                                    <th class="px-5 py-3 cursor-pointer hover:text-[var(--accent)]" onclick="sortClientHistoryByKey('check_in_date')">Séjour</th>
                                    <th class="px-5 py-3 cursor-pointer hover:text-[var(--accent)]" onclick="sortClientHistoryByKey('receptionist')">Pris par</th>
                                    <th class="px-5 py-3 cursor-pointer hover:text-[var(--accent)]" onclick="sortClientHistoryByKey('status')">Check-in</th>
                                    <th class="px-5 py-3 cursor-pointer hover:text-[var(--accent)]" onclick="sortClientHistoryByKey('invoice_status')">Facture</th>
                                    <th class="px-5 py-3 cursor-pointer hover:text-[var(--accent)]" onclick="sortClientHistoryByKey('payment_status')">Paiement</th>
                                    <th class="px-5 py-3 cursor-pointer hover:text-[var(--accent)]" onclick="sortClientHistoryByKey('deposit_amount_ariary')">Acompte</th>
                                    <th class="px-5 py-3 cursor-pointer hover:text-[var(--accent)]" onclick="sortClientHistoryByKey('fixed_total_price')">Total</th>
                                    <th class="px-5 py-3 cursor-pointer hover:text-[var(--accent)]" onclick="sortClientHistoryByKey('balance_amount_ariary')">Reste à payer</th>
                                </tr>
                            </thead>
                            <tbody id="client-history-table-body" class="divide-y divide-[rgba(68,52,39,0.08)]"></tbody>
                        </table>
                    </div>
                </article>

                <!-- Sélecteur d'onglets déplacé ici, après le Prix moyen -->
                <article class="bento-card col-span-1 p-4 sm:p-5 xl:col-span-8 xl:col-start-1">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div class="flex flex-wrap gap-2">
                            <button onclick="switchTab('predictions')" id="btn-predictions" class="pill-btn active inline-flex items-center justify-center gap-2 px-4 text-sm font-bold">
                                <svg class="icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4 16 5-5 4 4 7-7"/><path stroke-linecap="round" stroke-linejoin="round" d="M14 8h6v6"/></svg>
                                Prévisions
                            </button>
                            <button onclick="switchTab('pricing')" id="btn-pricing" class="pill-btn inline-flex items-center justify-center gap-2 px-4 text-sm font-bold text-[var(--muted)] hover:bg-white/60 hover:text-[var(--ink)]">
                                <svg class="icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7H4"/><path stroke-linecap="round" stroke-linejoin="round" d="M16 12H4"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 17H4"/></svg>
                                Tarifs
                            </button>
                            <button onclick="switchTab('reservations')" id="btn-reservations" class="pill-btn inline-flex items-center justify-center gap-2 px-4 text-sm font-bold text-[var(--muted)] hover:bg-white/60 hover:text-[var(--ink)]">
                                <svg class="icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3"/><path stroke-linecap="round" stroke-linejoin="round" d="M16 7V3"/><path stroke-linecap="round" stroke-linejoin="round" d="M4 11h16"/><path stroke-linecap="round" stroke-linejoin="round" d="M5 5h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z"/></svg>
                                Réservations
                            </button>
                            <button onclick="switchTab('finance')" id="btn-finance" class="pill-btn inline-flex items-center justify-center gap-2 px-4 text-sm font-bold text-[var(--muted)] hover:bg-white/60 hover:text-[var(--ink)]">
                                <svg class="icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 19h16"/><path stroke-linecap="round" stroke-linejoin="round" d="M7 16V9"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V5"/><path stroke-linecap="round" stroke-linejoin="round" d="M17 16v-4"/></svg>
                                Performance
                            </button>
                        </div>

                        <div class="relative self-start lg:self-auto">
                            <button type="button" id="settings-trigger" class="pill-btn sand-panel inline-flex items-center justify-center gap-2 px-4 text-sm font-bold text-[var(--ink)]">
                                <svg class="icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9"/><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 6h2"/><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 6a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12h9"/><path stroke-linecap="round" stroke-linejoin="round" d="M17.5 12h2"/><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 12a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 18h9"/><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 18h2"/><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 18a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0Z"/></svg>
                                Réglages
                            </button>

                            <div id="settings-panel" class="floating-panel sand-panel-strong absolute bottom-[calc(100%+12px)] right-0 z-20 w-[320px] rounded-[28px] p-5">
                                <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-[#8f745b]">Panneau flottant</p>
                                <div class="mt-4 space-y-4">
                                    <div>
                                        <label for="prediction-days" class="mb-2 block text-sm font-bold text-[var(--muted)]">Horizon de prévision</label>
                                        <select id="prediction-days" onchange="loadAILogic()" class="h-11 w-full rounded-full border border-[rgba(68,52,39,0.12)] bg-white/80 px-4 text-sm font-semibold text-[var(--ink)] outline-none transition focus:border-[var(--accent)] focus:ring-2 focus:ring-[rgba(31,118,101,0.12)]">
                                            <option value="1">Aujourd'hui</option>
                                            <option value="7">7 jours</option>
                                            <option value="15">15 jours</option>
                                            <option value="30" selected>30 jours</option>
                                            <option value="60">60 jours</option>
                                            <option value="90">90 jours</option>
                                            <option value="120">120 jours</option>
                                        </select>
                                    </div>
                                    <div class="sand-panel rounded-[24px] px-4 py-3">
                                        <p class="text-[11px] font-extrabold uppercase tracking-[0.18em] text-[#8f745b]">CA estimé par IA</p>
                                        <p class="display-serif mt-2 text-3xl font-semibold text-[var(--ink)]" id="stat-ca-ia">0 Ar</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </article>

                <article class="bento-card col-span-1 p-6 xl:col-span-4">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-[#8f745b]">Narration financière</p>
                    <p class="display-serif mt-4 text-4xl font-semibold leading-none text-[var(--ink)]" id="finance-total-ai">0 Ar</p>
                    <p class="mt-4 text-sm leading-6 text-[var(--muted)]">Lecture synthétique du potentiel journalier issu de la tarification dynamique.</p>
                </article>

                <section id="tab-predictions" class="tab-content col-span-1 xl:col-span-8">
                    <article class="bento-card p-6 sm:p-7">
                        <div class="mb-6 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                            <div>
                                <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-[#8f745b]">Prévision d'occupation</p>
                                <h2 class="display-serif mt-2 text-4xl font-semibold text-[var(--ink)]">Vagues d'occupation</h2>
                                <p class="mt-2 text-sm text-[var(--muted)]">Volume estimé par jour, toutes catégories confondues.</p>
                            </div>
                        </div>
                        <div class="h-[360px] w-full sm:h-[430px]">
                            <canvas id="predictionChart"></canvas>
                        </div>
                    </article>
                </section>

                <section id="tab-pricing" class="tab-content hidden col-span-1 xl:col-span-12">
                    <article class="bento-card p-6 sm:p-7">
                        <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-[#8f745b]">Tarifs par catégorie</p>
                        <h2 class="display-serif mt-2 text-4xl font-semibold text-[var(--ink)]">Édition tarifaire</h2>
                        <p class="mt-2 text-sm text-[var(--muted)]">Prix proposés pour la date sélectionnée.</p>
                        <div class="table-shell mobile-card-table mt-6">
                            <table class="data-table w-full text-left text-sm">
                                <thead>
                                    <tr>
                                        <th class="px-5 py-3">Catégorie</th>
                                        <th class="px-5 py-3">Modèle</th>
                                        <th class="px-5 py-3">Prix fixe</th>
                                        <th class="px-5 py-3">Prix ajusté IA</th>
                                    </tr>
                                </thead>
                                <tbody id="pricing-table-body" class="divide-y divide-[rgba(68,52,39,0.08)]"></tbody>
                            </table>
                        </div>
                    </article>
                </section>

                <section id="tab-reservations" class="tab-content hidden col-span-1 xl:col-span-12">
                    <article class="bento-card p-6 sm:p-7">
                        <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-[#8f745b]">Réservations actives</p>
                        <h2 class="display-serif mt-2 text-4xl font-semibold text-[var(--ink)]">Salon des arrivées</h2>
                        <p class="mt-2 text-sm text-[var(--muted)]">Clients présents ou attendus à la date sélectionnée.</p>
                        <div class="table-shell mobile-card-table mt-6">
                            <table class="data-table w-full text-left text-sm">
                                <thead>
                                    <tr>
                                        <th class="px-5 py-3 cursor-pointer hover:text-[var(--accent)]" onclick="sortReservations('reference')">Référence</th>
                                        <th class="px-5 py-3 cursor-pointer hover:text-[var(--accent)]" onclick="sortReservations('client_name')">Client</th>
                                        <th class="px-5 py-3 cursor-pointer hover:text-[var(--accent)]" onclick="sortReservations('contact')">Contact</th>
                                        <th class="px-5 py-3">Chambre(s)</th>
                                        <th class="px-5 py-3 cursor-pointer hover:text-[var(--accent)]" onclick="sortReservations('check_in')">Séjour</th>
                                        <th class="px-5 py-3">Pris par</th>
                                        <th class="px-5 py-3 cursor-pointer hover:text-[var(--accent)]" onclick="sortReservations('status')">Check-in</th>
                                        <th class="px-5 py-3">Paiement</th>
                                        <th class="px-5 py-3">Acompte</th>
                                        <th class="px-5 py-3 cursor-pointer hover:text-[var(--accent)]" onclick="sortReservations('fixed_total_price')">Total</th>
                                        <th class="px-5 py-3 cursor-pointer hover:text-[var(--accent)]" onclick="sortReservations('balance_amount_ariary')">Reste à payer</th>
                                    </tr>
                                </thead>
                                <tbody id="reservations-table-body" class="divide-y divide-[rgba(68,52,39,0.08)]"></tbody>
                            </table>
                        </div>
                    </article>
                </section>

                <section id="tab-finance" class="tab-content hidden col-span-1 xl:col-span-12">
                    <div class="grid grid-cols-1 gap-4 xl:grid-cols-12">
                        <article class="bento-card p-6 sm:p-7 xl:col-span-8">
                            <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-[#8f745b]">Indicateurs financiers</p>
                            <h2 class="display-serif mt-2 text-4xl font-semibold text-[var(--ink)]">Ondulation des revenus</h2>
                            <p class="mt-2 text-sm text-[var(--muted)]">Comparaison entre chiffre d'affaires officiel, en attente et estimé.</p>
                            <div class="mt-6 h-[320px]">
                                <canvas id="financeChart"></canvas>
                            </div>
                        </article>

                        <aside class="xl:col-span-4">
                            <article class="bento-card h-full p-6">
                                <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-[#8f745b]">Synthèse du jour</p>
                                <div class="mt-6 space-y-4">
                                    <div class="sand-panel rounded-[24px] p-4">
                                        <p class="text-[11px] font-extrabold uppercase tracking-[0.18em] text-[#8f745b]">CA officiel</p>
                                        <p class="display-serif mt-2 text-4xl font-semibold text-[var(--ink)]" id="finance-total-official">0 Ar</p>
                                    </div>
                                    <div class="sand-panel rounded-[24px] p-4">
                                        <p class="text-[11px] font-extrabold uppercase tracking-[0.18em] text-[#8f745b]">CA en attente</p>
                                        <p class="display-serif mt-2 text-4xl font-semibold text-[var(--ink)]" id="finance-total-pending">0 Ar</p>
                                    </div>
                                    <div class="sand-panel rounded-[24px] p-4">
                                        <p class="text-[11px] font-extrabold uppercase tracking-[0.18em] text-[#8f745b]">CA estimé</p>
                                        <p class="display-serif mt-2 text-4xl font-semibold text-[var(--ink)]" id="finance-total-ai-side">0 Ar</p>
                                    </div>
                                </div>
                            </article>
                        </aside>

                        <article class="bento-card p-6 sm:p-7 xl:col-span-12">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                                <div>
                                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-[#8f745b]">Simulation IA</p>
                                    <h3 class="display-serif mt-2 text-3xl font-semibold text-[var(--ink)]">Gain potentiel par journée</h3>
                                    <p class="mt-2 text-sm text-[var(--muted)]">Comparaison entre le prix fixe et les prix IA appliqués aux chambres réellement occupées ou attendues.</p>
                                </div>
                                <div class="sand-panel rounded-[18px] px-5 py-4">
                                    <p class="text-[11px] font-extrabold uppercase tracking-[0.18em] text-[#8f745b]">Écart total</p>
                                    <p class="display-serif mt-1 text-3xl font-semibold text-[var(--ink)]" id="ai-summary-delta">0 Ar</p>
                                </div>
                            </div>
                            <div class="table-shell mobile-card-table mt-6">
                                <table class="data-table w-full text-left text-sm">
                                    <thead>
                                        <tr>
                                            <th class="px-5 py-3">Date</th>
                                            <th class="px-5 py-3">Chambres</th>
                                            <th class="px-5 py-3">CA prix fixe</th>
                                            <th class="px-5 py-3">CA IA simulé</th>
                                            <th class="px-5 py-3">Gain / perte</th>
                                        </tr>
                                    </thead>
                                    <tbody id="ai-summary-table-body" class="divide-y divide-[rgba(68,52,39,0.08)]"></tbody>
                                </table>
                            </div>
                        </article>
                    </div>
                </section>

            </section>
        </main>
    </div>

    <script>
        let currentChart = null;
        let financeChart = null;
        let globalAiDailyCa = 0;
        let allReservationsData = [];
        let clientHistoryData = [];
        let sortDirection = 1;
        let lastSortKey = '';
        let clientHistorySortKey = 'check_in_date';
        let clientHistorySortDirection = -1;

        const chartGridColor = 'rgba(117, 105, 94, 0.16)';
        const chartTextColor = '#75695e';

        function formatMoney(value) {
            return `${Math.round(value || 0).toLocaleString()} Ar`;
        }

        function normalizeRoomLabel(value) {
            return (value || '')
                .toString()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toLowerCase()
                .replace(/\s+/g, ' ')
                .trim();
        }

        function roomSortRank(key) {
            const normalized = normalizeRoomLabel(key);
            if (normalized.includes('double')) return 0;
            if (normalized.includes('twin')) return 1;
            if (normalized.includes('triple')) return 2;
            if (normalized.includes('famil')) return 3;
            if (normalized.includes('suite')) return 4;
            return 5;
        }

        function sortRoomCategories(keys) {
            return [...keys].sort((a, b) => {
                const rankA = roomSortRank(a);
                const rankB = roomSortRank(b);
                if (rankA !== rankB) return rankA - rankB;
                return normalizeRoomLabel(a).localeCompare(normalizeRoomLabel(b), 'fr');
            });
        }

        function cacheKeyFor(url, extra = '') {
            return `hestia-dashboard:${url}${extra ? `:${extra}` : ''}`;
        }

        function readCache(key) {
            try {
                const value = localStorage.getItem(key);
                return value ? JSON.parse(value) : null;
            } catch (_) {
                return null;
            }
        }

        function writeCache(key, value) {
            try {
                localStorage.setItem(key, JSON.stringify(value));
            } catch (_) {
                // Ignore storage quota / privacy errors.
            }
        }

        async function safeFetchJson(url, cacheKey = null, options = {}) {
            const controller = new AbortController();
            const timeoutMs = options.timeoutMs ?? 4000;
            const timeoutId = setTimeout(() => controller.abort(), timeoutMs);
            const fetchOptions = {
                ...options.fetchOptions,
                signal: controller.signal,
            };

            try {
                const response = await fetch(url, fetchOptions);
                clearTimeout(timeoutId);
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                const data = await response.json();
                if (cacheKey) {
                    writeCache(cacheKey, data);
                }
                return { data, fromCache: false, online: true };
            } catch (error) {
                clearTimeout(timeoutId);
                if (cacheKey) {
                    const cached = readCache(cacheKey);
                    if (cached) {
                        return { data: cached, fromCache: true, online: false, error };
                    }
                }
                throw error;
            }
        }

        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('.pill-btn').forEach(el => {
                if (!el.id.startsWith('btn-')) return;
                el.classList.remove('active');
                el.classList.add('text-[var(--muted)]');
            });

            document.getElementById('tab-' + tabId).classList.remove('hidden');
            const activeBtn = document.getElementById('btn-' + tabId);
            activeBtn.classList.add('active');
            activeBtn.classList.remove('text-[var(--muted)]');

            if (tabId === 'finance') {
                updateFinanceChart();
            }
        }

        function toggleSettingsPanel(forceState = null) {
            const panel = document.getElementById('settings-panel');
            const nextState = forceState === null ? !panel.classList.contains('open') : forceState;
            panel.classList.toggle('open', nextState);
        }

        function refreshAll() {
            loadAILogic();
            runAudit();
            loadReservations();
            loadAiRevenueSummary();
            searchClientHistory(true);
        }

        function updateConnectionState(isFallback) {
            const connectionDot = document.getElementById('connection-dot');
            const connectionText = document.getElementById('connection-text');
            const connectionContainer = document.getElementById('connection-text-container');

            if (isFallback) {
                connectionDot.className = 'h-2.5 w-2.5 rounded-full bg-slate-400';
                connectionContainer.className = 'mt-1 flex items-center gap-2 text-sm font-semibold text-slate-600';
                connectionText.innerText = 'Prix de base appliqués';
                return;
            }

            connectionDot.className = 'h-2.5 w-2.5 rounded-full bg-emerald-500';
            connectionContainer.className = 'mt-1 flex items-center gap-2 text-sm font-semibold text-emerald-700';
            connectionText.innerText = 'Active';
        }

        function loadAILogic() {
            const daysSelected = document.getElementById('prediction-days').value;
            const pricingDate = document.getElementById('global-date').value;
            const cacheKey = cacheKeyFor('/api/dashboard/predictions', `${daysSelected}:${pricingDate}`);

            safeFetchJson(`/api/dashboard/predictions?days=${daysSelected}&start_date=${pricingDate}`, cacheKey, {
                timeoutMs: 4500,
            })
                .then(({ data, fromCache, online }) => {
                    if (data.status !== 'success') {
                        updateConnectionState(true);
                        return;
                    }

                    updateConnectionState(Boolean(data.is_fallback || fromCache || !online));

                    const tableBody = document.getElementById('pricing-table-body');
                    tableBody.innerHTML = '';
                    globalAiDailyCa = 0;

                    if (!data.results || Object.keys(data.results).length === 0) {
                        tableBody.innerHTML = '<tr><td colspan="4" class="px-5 py-8 text-center text-[var(--muted)]">Aucune donnée tarifaire disponible.</td></tr>';
                        return;
                    }

                    sortRoomCategories(Object.keys(data.results)).forEach(categoryKey => {
                        const categoryData = data.results[categoryKey];
                        const specificDay = categoryData.find(d => d.date === pricingDate) || categoryData[0];
                        const parts = categoryKey.split(' - ');
                        const type = parts[0];
                        const model = parts.slice(1).join(' - ') || 'Standard';
                        const fixedPrice = specificDay.fixed_price_ariary || specificDay.base_price || 0;
                        const adjustedPrice = specificDay.adjusted_price_ariary || specificDay.suggested_price_ariary || fixedPrice;
                        const isSamePrice = adjustedPrice === fixedPrice;
                        const fixedLabel = specificDay.is_fixed_price ? 'Prix non ajustable' : 'Prix de référence';

                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td data-label="Catégorie" class="px-5 py-4 font-bold text-[var(--ink)]">${type}</td>
                            <td data-label="Modèle" class="px-5 py-4">${model}</td>
                            <td data-label="Prix fixe" class="px-5 py-4">
                                <div class="font-semibold">${formatMoney(fixedPrice)}</div>
                                <div class="text-xs text-[var(--muted)]">${fixedLabel}</div>
                            </td>
                            <td data-label="Prix ajusté IA" class="px-5 py-4">
                                <span class="inline-flex rounded-full ${isSamePrice ? 'bg-white/70 text-[var(--muted)]' : 'bg-[var(--accent-soft)] text-[var(--accent)]'} px-3 py-1 text-sm font-black">
                                    ${formatMoney(adjustedPrice)}
                                </span>
                            </td>
                        `;
                        tableBody.appendChild(row);

                        if (specificDay && specificDay.date === pricingDate) {
                            globalAiDailyCa += adjustedPrice * (specificDay.predicted_occupancy || 0);
                        }
                    });

                    document.getElementById('stat-ca-ia').innerText = formatMoney(globalAiDailyCa);
                    document.getElementById('finance-total-ai').innerText = formatMoney(globalAiDailyCa);
                    document.getElementById('finance-total-ai-side').innerText = formatMoney(globalAiDailyCa);
                    renderPredictionChart(data.results);
                    updateFinanceChart();
                })
                .catch(() => updateConnectionState(true));
        }

        function renderPredictionChart(results) {
            const firstCat = Object.keys(results)[0];
            const labels = results[firstCat].map(p => p.date);
            const values = labels.map((date, i) => {
                let sum = 0;
                Object.keys(results).forEach(cat => {
                    if (results[cat][i]) {
                        sum += results[cat][i].predicted_occupancy || 0;
                    }
                });
                return Math.min(40, sum);
            });

            const ctx = document.getElementById('predictionChart').getContext('2d');
            if (currentChart) {
                currentChart.destroy();
            }

            currentChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels,
                    datasets: [{
                        label: 'Chambres occupées estimées',
                        data: values,
                        borderColor: '#1f7665',
                        backgroundColor: 'rgba(31, 118, 101, 0.15)',
                        fill: true,
                        tension: 0.48,
                        cubicInterpolationMode: 'monotone',
                        pointRadius: labels.length > 30 ? 0 : 2,
                        pointHoverRadius: 5,
                        pointBackgroundColor: '#1f7665',
                        borderWidth: 2.5,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            labels: {
                                color: '#4a3f37',
                                boxWidth: 14,
                                font: { weight: 'bold' }
                            }
                        }
                    },
                    scales: {
                        y: {
                            min: 0,
                            max: 40,
                            ticks: { stepSize: 5, color: chartTextColor },
                            grid: { color: chartGridColor, drawBorder: false },
                            title: {
                                display: true,
                                text: 'Nombre de chambres',
                                color: chartTextColor
                            }
                        },
                        x: {
                            ticks: { color: chartTextColor, maxRotation: 0, autoSkip: true },
                            grid: { display: false }
                        }
                    }
                }
            });
        }

        function loadReservations() {
            const date = document.getElementById('global-date').value;
            const cacheKey = cacheKeyFor('/api/active-reservations', date);
            safeFetchJson(`/api/active-reservations?date=${date}`, cacheKey, { timeoutMs: 4000 })
                .then(({ data, fromCache }) => {
                    allReservationsData = data;
                    renderReservationsTable(data);
                    if (fromCache) {
                        const summary = document.getElementById('reservations-table-summary');
                        if (summary) {
                            summary.textContent = 'Données locales affichées en mode dégradé.';
                        }
                    }
                })
                .catch(() => {
                    allReservationsData = [];
                    renderReservationsTable([]);
                });
        }

        function sortReservations(key) {
            if (lastSortKey === key) {
                sortDirection *= -1;
            } else {
                sortDirection = 1;
                lastSortKey = key;
            }

            const sorted = sortRows([...allReservationsData], key, sortDirection);
            renderReservationsTable(sorted);
        }

        function sortRows(rows, key, direction = 1) {
            const numericKeys = ['fixed_total_price', 'paid_amount_ariary', 'balance_amount_ariary', 'total_price', 'deposit_amount_ariary'];

            return rows.sort((a, b) => {
                let valA = a[key];
                let valB = b[key];

                if (numericKeys.includes(key)) {
                    valA = Number(valA || 0);
                    valB = Number(valB || 0);
                } else {
                    valA = (valA || '').toString().toLowerCase();
                    valB = (valB || '').toString().toLowerCase();
                }

                if (valA < valB) return -1 * direction;
                if (valA > valB) return 1 * direction;
                return 0;
            });
        }

        function clientHistorySortLabel(key) {
            const labels = {
                period: 'Période',
                reference: 'Référence',
                client_name: 'Client',
                contact: 'Contact',
                check_in_date: 'Séjour',
                receptionist: 'Pris par',
                status: 'Check-in',
                invoice_status: 'Facture',
                payment_status: 'Paiement',
                deposit_amount_ariary: 'Acompte',
                fixed_total_price: 'Total',
                balance_amount_ariary: 'Reste à payer',
            };

            return labels[key] || key;
        }

        function statusBadge(status, paymentStatus = null, cancelledByName = null, processedByName = null) {
            const normalizedStatus = (status || '').toString();
            const normalizedPayment = (paymentStatus || '').toString();
            if (status === 'en_attente') {
                return '<span class="inline-flex rounded-full bg-white/75 px-3 py-1 text-xs font-black text-sky-700">En attente</span>';
            }
            if (normalizedStatus === 'arrive' || normalizedStatus === 'arrive_paid' || normalizedStatus === 'arrive_unpaid') {
                const effectivePayment = normalizedStatus === 'arrive_paid'
                    ? 'paid'
                    : (normalizedStatus === 'arrive_unpaid' ? 'unpaid' : normalizedPayment);
                const paymentLabel = effectivePayment === 'paid'
                    ? 'Arrivé payé'
                    : (effectivePayment === 'partial' || effectivePayment === 'unpaid' || effectivePayment === 'unbilled'
                        ? 'Arrivé non payé'
                        : 'Arrivé');
                const suffix = processedByName ? `<span class="block text-[10px] font-semibold text-[var(--muted)]">par ${processedByName}</span>` : '';
                return `<span class="inline-flex flex-col rounded-full bg-white/75 px-3 py-2 text-xs font-black leading-tight text-emerald-700">${paymentLabel}${suffix}</span>`;
            }
            if (normalizedStatus === 'annule') {
                const suffix = cancelledByName ? `<span class="block text-[10px] font-semibold text-[var(--muted)]">par ${cancelledByName}</span>` : '';
                return `<span class="inline-flex flex-col rounded-full bg-white/75 px-3 py-2 text-xs font-black leading-tight text-rose-700">Annulé${suffix}</span>`;
            }
            return `<span class="inline-flex rounded-full bg-white/75 px-3 py-1 text-xs font-black text-[var(--muted)]">${normalizedStatus || 'N/A'}</span>`;
        }

        function formatShortDate(dateStr) {
            const raw = (dateStr || '').toString();
            if (!raw) return 'N/A';
            const dateOnly = raw.split('T')[0];
            const parts = dateOnly.split('-');
            if (parts.length !== 3) return raw;
            return `${parts[2]}/${parts[1]}`;
        }

        function formatStayNights(checkIn, checkOut) {
            const startRaw = (checkIn || '').toString().split('T')[0];
            const endRaw = (checkOut || '').toString().split('T')[0];
            if (!startRaw || !endRaw) return 'N/A';

            const start = new Date(`${startRaw}T00:00:00`);
            const end = new Date(`${endRaw}T00:00:00`);
            if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) {
                return 'N/A';
            }

            const diff = Math.round((end.getTime() - start.getTime()) / 86400000);
            const nights = Math.max(0, diff);
            return `${nights} nuit${nights > 1 ? 's' : ''}`;
        }

        function formatStayRange(checkIn, checkOut) {
            const nights = formatStayNights(checkIn, checkOut);
            return `${formatShortDate(checkIn)} à ${formatShortDate(checkOut)} (${nights})`;
        }

        function actorText(name, role = null) {
            const normalizedName = (name || '').toString().trim();
            const normalizedRole = (role || '').toString().trim();
            if (!normalizedName || normalizedName === 'N/A') {
                return 'N/A';
            }
            return normalizedRole && normalizedRole !== 'N/A'
                ? `${normalizedName} / ${normalizedRole}`
                : normalizedName;
        }

        function infoPill(label, accent = false) {
            const classes = accent
                ? 'bg-white/75 text-[var(--accent)] border border-[rgba(42,34,27,0.15)]'
                : 'bg-white/75 text-[var(--muted)] border border-[rgba(68,52,39,0.10)]';
            return `<span class="inline-flex rounded-full px-3 py-1 text-xs font-black ${classes}">${label}</span>`;
        }

        function checkInBadge(res) {
            const status = (res.status || '').toString();
            const checkInActor = actorText(res.check_in_by, res.check_in_role);
            const modifiedBy = actorText(res.modified_by, res.modified_by_role);

            if (status === 'en_attente') {
                const modifiedLine = modifiedBy !== 'N/A'
                    ? `<button type="button" class="text-left text-[10px] font-semibold text-[var(--accent)] hover:underline" onclick="openReservationAuditModal('${res.reference || ''}')">Modifié par ${modifiedBy}</button>`
                    : '';
                return `
                    <div class="flex flex-col gap-1">
                        ${infoPill('En attente')}
                        <span class="text-[10px] font-semibold text-[var(--muted)]">Effectué par : ${checkInActor}</span>
                        ${modifiedLine}
                    </div>
                `;
            }

            if (status === 'annule') {
                return `
                    <div class="flex flex-col gap-1">
                        ${infoPill('Annulé', true)}
                        <span class="text-[10px] font-semibold text-[var(--muted)]">Par : ${actorText(res.cancelled_by_name)}</span>
                    </div>
                `;
            }

            const modifiedLine = modifiedBy !== 'N/A'
                ? `<button type="button" class="text-left text-[10px] font-semibold text-[var(--accent)] hover:underline" onclick="openReservationAuditModal('${res.reference || ''}')">Modifié par ${modifiedBy}</button>`
                : '<span class="text-[10px] font-semibold text-[var(--muted)]">Modifié par : N/A</span>';

            return `
                <div class="flex flex-col gap-1">
                    ${infoPill(`Effectué par ${checkInActor}`)}
                    ${modifiedLine}
                </div>
            `;
        }

        function paymentBadgeDetailed(res) {
            const methods = (res.payment_methods_display || '').toString().trim();
            const paid = Number(res.paid_amount_ariary || 0);
            const status = (res.payment_status || '').toString();
            const actor = actorText(res.latest_payment_processed_by, res.latest_payment_processed_by_role);
            const operator = (res.latest_payment_operator || '').toString().trim();
            const methodLine = operator && (res.latest_payment_method || '').toString() === 'Mobile Money'
                ? `${methods || res.latest_payment_method || 'N/A'} / ${operator}`
                : (methods || res.latest_payment_method || 'N/A');

            if (!methodLine && actor === 'N/A' && !paid) {
                return infoPill('N/A');
            }

            const statusLabel = status === 'paid'
                ? 'Payé'
                : (status === 'partial' ? 'Partiel' : (status === 'unpaid' ? 'En attente' : (status || 'N/A')));

            return `
                <div class="flex flex-col gap-1">
                    <span class="font-black text-[var(--ink)]">${methodLine}</span>
                    <span class="text-[11px] font-semibold text-[var(--muted)]">${statusLabel} · ${formatMoney(paid)}${actor !== 'N/A' ? ` · ${actor}` : ''}</span>
                </div>
            `;
        }

        function depositBadgeDetailed(res) {
            const methods = (res.latest_deposit_method || '').toString().trim();
            const actor = actorText(res.latest_deposit_processed_by, res.latest_deposit_processed_by_role);
            const operator = (res.latest_deposit_operator || '').toString().trim();
            const depositAmount = Number(res.deposit_amount_ariary || 0);
            const methodLine = operator && methods === 'Mobile Money'
                ? `${methods} / ${operator}`
                : (methods || 'N/A');

            if (!methods && actor === 'N/A' && !depositAmount) {
                return infoPill('N/A');
            }

            return `
                <div class="flex flex-col gap-1">
                    <span class="font-black text-[var(--ink)]">${methodLine}</span>
                    <span class="text-[11px] font-semibold text-[var(--muted)]">Acompte ${formatMoney(depositAmount)}${actor !== 'N/A' ? ` · ${actor}` : ''}</span>
                </div>
            `;
        }

        function openReservationAuditModal(reference) {
            const rows = allReservationsData || [];
            const reservation = rows.find((row) => (row.reference || '').toString() === reference);
            if (!reservation) return;
            const details = reservation.modified_details || reservation.last_action_details;
            const entries = details && typeof details === 'object' ? Object.entries(details) : [];
            if (entries.length === 0) {
                alert('Aucun détail de modification disponible.');
                return;
            }

            const existing = document.getElementById('audit-modal-overlay');
            if (existing) existing.remove();

            const overlay = document.createElement('div');
            overlay.id = 'audit-modal-overlay';
            overlay.className = 'fixed inset-0 z-[999] flex items-center justify-center bg-black/50 px-4';

            const body = entries.map(([key, value]) => {
                const before = value && typeof value === 'object' ? value.before : null;
                const after = value && typeof value === 'object' ? value.after : null;
                return `
                    <div class="mb-4 rounded-2xl border border-[rgba(68,52,39,0.10)] bg-white p-4">
                        <div class="mb-2 font-black text-[var(--ink)]">${key.replaceAll('_', ' ')}</div>
                        <div class="text-sm text-[var(--muted)]">Avant : ${Array.isArray(before) ? before.join(', ') : (before ?? 'N/A')}</div>
                        <div class="text-sm text-[var(--muted)]">Après : ${Array.isArray(after) ? after.join(', ') : (after ?? 'N/A')}</div>
                    </div>
                `;
            }).join('');

            overlay.innerHTML = `
                <div class="w-full max-w-2xl rounded-[28px] bg-[var(--sand)] p-4 shadow-2xl">
                    <div class="flex items-start justify-between gap-4 border-b border-[rgba(68,52,39,0.10)] pb-4">
                        <div>
                            <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-[#8f745b]">Audit</p>
                            <h3 class="display-serif mt-1 text-3xl font-semibold text-[var(--ink)]">Modification de ${reservation.reference || 'N/A'}</h3>
                            <p class="mt-1 text-sm text-[var(--muted)]">Par ${actorText(reservation.modified_by, reservation.modified_by_role)}${reservation.modified_at ? ` · ${reservation.modified_at}` : ''}</p>
                        </div>
                        <button type="button" class="rounded-full border border-[rgba(68,52,39,0.10)] bg-white px-3 py-1 text-sm font-black text-[var(--ink)]" onclick="document.getElementById('audit-modal-overlay')?.remove()">Fermer</button>
                    </div>
                    <div class="mt-4 max-h-[60vh] overflow-auto">
                        ${body}
                    </div>
                </div>
            `;

            overlay.addEventListener('click', (event) => {
                if (event.target === overlay) overlay.remove();
            });
            document.body.appendChild(overlay);
        }

        function sourceBadge(source) {
            const classes = {
                Booking: 'bg-white/75 text-indigo-700',
                Appel: 'bg-white/75 text-teal-700',
                Mail: 'bg-white/75 text-violet-700',
            };
            return `<span class="inline-flex rounded-full ${(classes[source] || 'bg-white/75 text-[var(--muted)]')} px-3 py-1 text-xs font-bold">${source || 'N/A'}</span>`;
        }

        function periodBadge(period) {
            const normalized = (period || '').toString();
            const classes = {
                passé: 'bg-white/75 text-slate-700',
                présent: 'bg-white/75 text-emerald-700',
                futur: 'bg-white/75 text-indigo-700',
                annulé: 'bg-white/75 text-rose-700',
            };
            return `<span class="inline-flex rounded-full ${(classes[normalized] || 'bg-white/75 text-[var(--muted)]')} px-3 py-1 text-xs font-black">${normalized || 'N/A'}</span>`;
        }

        function invoiceBadge(res) {
            const invoiceStatus = (res.invoice_status || '').toString();
            const paymentStatus = (res.payment_status || '').toString();
            if (!res.invoice_number) {
                return '<span class="inline-flex rounded-full bg-white/75 px-3 py-1 text-xs font-black text-[var(--muted)]">Non générée</span>';
            }

            const label = invoiceStatus === 'paid' || paymentStatus === 'paid'
                ? 'Payée'
                : (paymentStatus === 'partial' || paymentStatus === 'unpaid'
                    ? 'En attente'
                    : 'Ouverte');
            const color = invoiceStatus === 'paid' || paymentStatus === 'paid'
                ? 'text-emerald-700'
                : 'text-amber-700';

            const pdfLink = res.pdf_url
                ? `<a href="${res.pdf_url}" target="_blank" rel="noopener" class="mt-2 inline-flex items-center gap-1 rounded-full border border-[rgba(68,52,39,0.12)] bg-white/80 px-3 py-1 text-[11px] font-black text-[var(--ink)] transition hover:bg-white">
                        <span>Voir PDF</span>
                    </a>`
                : '';

            return `<div class="flex flex-col items-start">
                <span class="inline-flex rounded-full bg-white/75 px-3 py-1 text-xs font-black ${color}">${label}</span>
                ${pdfLink}
            </div>`;
        }

        function paymentBadge(res) {
            const methods = (res.payment_methods_display || '').toString().trim();
            const paid = Number(res.paid_amount_ariary || 0);
            const balance = Number(res.balance_amount_ariary || 0);
            const status = (res.payment_status || '').toString();
            const statusLabel = status === 'paid'
                ? 'Payé'
                : (status === 'partial' ? 'Partiel' : (status === 'unpaid' ? 'En attente' : (status || 'N/A')));
            const statusClass = status === 'paid'
                ? 'text-emerald-700'
                : (status === 'partial' ? 'text-amber-700' : 'text-[var(--muted)]');

            return `
                <div class="flex flex-col gap-1">
                    <span class="font-black text-[var(--ink)]">${methods || 'Aucune méthode'}</span>
                    <span class="text-[11px] font-semibold ${statusClass}">
                        ${statusLabel} · ${formatMoney(paid)}${balance > 0 ? ` · solde ${formatMoney(balance)}` : ''}
                    </span>
                </div>
            `;
        }

        function searchClientHistory(silent = false) {
            const query = document.getElementById('client-history-query')?.value?.trim() || '';
            const summary = document.getElementById('client-history-summary');
            const tableBody = document.getElementById('client-history-table-body');

            if (summary && !silent) {
                summary.textContent = query.length >= 2
                    ? 'Recherche en cours...'
                    : 'Chargement des réservations récentes...';
            }

            const cacheKey = cacheKeyFor('/api/dashboard/client-history', query.length >= 2 ? query.toLowerCase() : 'recent');
            const url = query.length >= 2
                ? `/api/dashboard/client-history?q=${encodeURIComponent(query)}`
                : '/api/dashboard/client-history';

            safeFetchJson(url, cacheKey, {
                timeoutMs: 4500,
            })
                .then(({ data, fromCache }) => {
                    if (data.status !== 'success') {
                        clientHistoryData = [];
                        if (summary) {
                            summary.textContent = 'Aucun résultat.';
                        }
                        if (tableBody) {
                            tableBody.innerHTML = '';
                        }
                        return;
                    }

                    clientHistoryData = data.data || [];
                    renderClientHistoryTable(query, clientHistoryData);
                    if (fromCache && summary) {
                        summary.textContent += ' Données locales affichées.';
                    }
                })
                .catch(() => {
                    clientHistoryData = [];
                    if (summary) {
                        summary.textContent = 'Recherche indisponible.';
                    }
                    if (tableBody) {
                        tableBody.innerHTML = '';
                    }
                });
        }

        function sortClientHistoryByKey(key) {
            if (!clientHistoryData || clientHistoryData.length === 0) {
                return;
            }

            if (clientHistorySortKey === key) {
                clientHistorySortDirection *= -1;
            } else {
                clientHistorySortKey = key;
                clientHistorySortDirection = ['check_in_date', 'balance_amount_ariary', 'fixed_total_price', 'deposit_amount_ariary'].includes(key)
                    ? -1
                    : 1;
            }

            const query = document.getElementById('client-history-query')?.value?.trim() || '';
            renderClientHistoryTable(query, clientHistoryData);
        }

        function renderClientHistoryTable(query, rows) {
            const tableBody = document.getElementById('client-history-table-body');
            const summary = document.getElementById('client-history-summary');
            if (!tableBody || !summary) {
                return;
            }

            tableBody.innerHTML = '';

            if (!rows || rows.length === 0) {
                summary.textContent = `Aucune réservation trouvée pour "${query}".`;
                tableBody.innerHTML = '<tr><td colspan="13" class="px-5 py-8 text-center text-[var(--muted)]">Aucun historique disponible.</td></tr>';
                return;
            }

            summary.textContent = `${rows.length} réservation(s) trouvée(s) pour "${query}". Tri : ${clientHistorySortLabel(clientHistorySortKey)} (${clientHistorySortDirection === 1 ? 'croissant' : 'décroissant'}).`;

            const sortedRows = sortRows([...rows], clientHistorySortKey, clientHistorySortDirection);

            sortedRows.forEach(res => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td data-label="Période" class="px-5 py-4">${periodBadge(res.period)}</td>
                    <td data-label="Référence" class="px-5 py-4 font-mono text-xs font-black text-[var(--accent)]">${res.reference || 'N/A'}</td>
                    <td data-label="Client" class="px-5 py-4 font-bold text-[var(--ink)]">${res.client_name || 'N/A'}</td>
                    <td data-label="Contact" class="px-5 py-4 text-xs">${res.contact || 'N/A'}</td>
                    <td data-label="Chambre(s)" class="px-5 py-4 text-xs">
                        <div class="font-mono font-black text-[var(--ink)]">${res.room_numbers || 'N/A'}</div>
                        <div class="mt-1 font-semibold text-[var(--muted)]">${res.rooms || 'N/A'}</div>
                    </td>
                    <td data-label="Séjour" class="px-5 py-4 text-xs">${formatStayRange(res.check_in_date || res.check_in, res.check_out_date || res.check_out)}</td>
                    <td data-label="Pris par" class="px-5 py-4 text-xs">${actorText(res.receptionist)}</td>
                    <td data-label="Check-in" class="px-5 py-4 text-xs">${checkInBadge(res)}</td>
                    <td data-label="Facture" class="px-5 py-4 text-xs">${invoiceBadge(res)}</td>
                    <td data-label="Paiement" class="px-5 py-4 text-xs">${paymentBadgeDetailed(res)}</td>
                    <td data-label="Acompte" class="px-5 py-4 text-xs">${depositBadgeDetailed(res)}</td>
                    <td data-label="Total" class="px-5 py-4 text-xs font-bold text-[var(--muted)]">${formatMoney(res.fixed_total_price || 0)}</td>
                    <td data-label="Reste à payer" class="px-5 py-4 text-xs font-black text-[var(--ink)]">${formatMoney(res.balance_amount_ariary || 0)}</td>
                `;
                tableBody.appendChild(row);
            });
        }

        function renderReservationsTable(data) {
            const tableBody = document.getElementById('reservations-table-body');
            tableBody.innerHTML = '';

            if (data.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="11" class="px-5 py-8 text-center text-[var(--muted)]">Aucune réservation active pour cette date.</td></tr>';
                return;
            }

            data.forEach(res => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td data-label="Référence" class="px-5 py-4 font-mono text-xs font-black text-[var(--accent)]">${res.reference}</td>
                    <td data-label="Client" class="px-5 py-4 font-bold text-[var(--ink)]">${res.client_name}</td>
                    <td data-label="Contact" class="px-5 py-4 text-xs">${res.contact}</td>
                    <td data-label="Chambre(s)" class="px-5 py-4 text-xs">
                        <div class="font-mono font-black text-[var(--ink)]">${res.room_numbers || 'N/A'}</div>
                        <div class="mt-1 font-semibold text-[var(--muted)]">${res.rooms || 'N/A'}</div>
                    </td>
                    <td data-label="Séjour" class="px-5 py-4 text-xs">${formatStayRange(res.check_in, res.check_out)}</td>
                    <td data-label="Pris par" class="px-5 py-4 text-xs">${actorText(res.receptionist)}</td>
                    <td data-label="Check-in" class="px-5 py-4 text-xs">${checkInBadge(res)}</td>
                    <td data-label="Paiement" class="px-5 py-4 text-xs">${paymentBadgeDetailed(res)}</td>
                    <td data-label="Acompte" class="px-5 py-4 text-xs">${depositBadgeDetailed(res)}</td>
                    <td data-label="Total" class="px-5 py-4 font-bold text-[var(--muted)]">${formatMoney(res.fixed_total_price)}</td>
                    <td data-label="Reste à payer" class="px-5 py-4 font-black text-[var(--ink)]">${formatMoney(res.balance_amount_ariary)}</td>
                `;
                tableBody.appendChild(row);
            });
        }

        function loadAiRevenueSummary() {
            const daysSelected = document.getElementById('prediction-days').value;
            const pricingDate = document.getElementById('global-date').value;
            const cacheKey = cacheKeyFor('/api/dashboard/ai-revenue-summary', `${daysSelected}:${pricingDate}`);
            safeFetchJson(`/api/dashboard/ai-revenue-summary?days=${daysSelected}&start_date=${pricingDate}`, cacheKey, {
                timeoutMs: 5000,
            })
                .then(({ data, fromCache }) => {
                    renderAiRevenueSummary(data);
                    if (fromCache) {
                        const tableBody = document.getElementById('ai-summary-table-body');
                        if (tableBody && !tableBody.children.length) {
                            tableBody.innerHTML = '<tr><td colspan="5" class="px-5 py-8 text-center text-[var(--muted)]">Mode dégradé actif.</td></tr>';
                        }
                    }
                })
                .catch(() => {
                    const tableBody = document.getElementById('ai-summary-table-body');
                    if (tableBody) {
                        tableBody.innerHTML = '<tr><td colspan="5" class="px-5 py-8 text-center text-[var(--muted)]">Récapitulatif IA indisponible.</td></tr>';
                    }
                });
        }

        function renderAiRevenueSummary(data) {
            const tableBody = document.getElementById('ai-summary-table-body');
            if (!tableBody) {
                return;
            }

            const rows = data.rows || [];
            document.getElementById('ai-summary-delta').innerText = formatMoney(data.totals?.delta_ariary || 0);
            tableBody.innerHTML = '';

            if (rows.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="5" class="px-5 py-8 text-center text-[var(--muted)]">Aucune journée à comparer.</td></tr>';
                return;
            }

            rows.forEach(rowData => {
                const delta = Number(rowData.delta_ariary || 0);
                const deltaClass = delta > 0 ? 'text-emerald-700' : (delta < 0 ? 'text-rose-700' : 'text-[var(--muted)]');
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td data-label="Date" class="px-5 py-4 font-mono text-xs font-black text-[var(--accent)]">${rowData.date}</td>
                    <td data-label="Chambres" class="px-5 py-4 font-bold text-[var(--ink)]">${rowData.room_count}</td>
                    <td data-label="CA prix fixe" class="px-5 py-4 font-semibold text-[var(--muted)]">${formatMoney(rowData.fixed_revenue_ariary)}</td>
                    <td data-label="CA IA simulé" class="px-5 py-4 font-black text-[var(--ink)]">${formatMoney(rowData.ai_revenue_ariary)}</td>
                    <td data-label="Gain / perte" class="px-5 py-4 font-black ${deltaClass}">${delta > 0 ? '+' : ''}${formatMoney(delta)}</td>
                `;
                tableBody.appendChild(row);
            });
        }

        function runAudit() {
            const dateSelected = document.getElementById('global-date').value;
            const cacheKey = cacheKeyFor('/api/dashboard/audit-date', dateSelected);
            safeFetchJson(`/api/dashboard/audit-date?date=${dateSelected}`, cacheKey, { timeoutMs: 3500 })
                .then(({ data }) => {
                    if (data.status !== 'success') {
                        return;
                    }

                    document.getElementById('stat-ca-official').innerText = formatMoney(data.daily_ca_official);
                    document.getElementById('stat-ca-pending').innerText = formatMoney(data.daily_ca_pending);
                    document.getElementById('stat-ca-total').innerText = formatMoney(data.total_ca);
                    document.getElementById('stat-rooms-confirmed').innerText = data.rooms_confirmed || 0;
                    document.getElementById('stat-rooms-estimated').innerText = data.rooms_estimated || 0;
                    document.getElementById('ca-period').innerText = data.period;

                    document.getElementById('finance-total-official').innerText = formatMoney(data.daily_ca_official);
                    document.getElementById('finance-total-pending').innerText = formatMoney(data.daily_ca_pending);

                    const totalRooms = 37;
                    const roomsConfirmed = data.rooms_confirmed || 0;
                    const occRate = (roomsConfirmed / totalRooms) * 100;
                    const adr = roomsConfirmed > 0 ? (data.daily_ca_official / roomsConfirmed) : 0;
                    const revpar = data.daily_ca_official / totalRooms;

                    document.getElementById('finance-occ-rate').innerText = `${occRate.toFixed(1)}%`;
                    document.getElementById('finance-adr').innerText = formatMoney(adr);
                    document.getElementById('finance-revpar').innerText = formatMoney(revpar);
                    updateFinanceChart(data);
                })
                .catch(() => {
                    const cached = readCache(cacheKey);
                    if (!cached || cached.status !== 'success') return;
                    document.getElementById('stat-ca-official').innerText = formatMoney(cached.daily_ca_official);
                    document.getElementById('stat-ca-pending').innerText = formatMoney(cached.daily_ca_pending);
                    document.getElementById('stat-ca-total').innerText = formatMoney(cached.total_ca);
                    document.getElementById('stat-rooms-confirmed').innerText = cached.rooms_confirmed || 0;
                    document.getElementById('stat-rooms-estimated').innerText = cached.rooms_estimated || 0;
                    document.getElementById('ca-period').innerText = cached.period;
                });
        }

        function updateFinanceChart(data = null) {
            const officialText = document.getElementById('stat-ca-official').innerText || '0';
            const pendingText = document.getElementById('stat-ca-pending').innerText || '0';
            const aiText = document.getElementById('stat-ca-ia').innerText || '0';
            const official = data?.daily_ca_official ?? Number(officialText.replace(/\D/g, ''));
            const pending = data?.daily_ca_pending ?? Number(pendingText.replace(/\D/g, ''));
            const estimated = Number(aiText.replace(/\D/g, ''));

            const canvas = document.getElementById('financeChart');
            if (!canvas) {
                return;
            }

            const ctx = canvas.getContext('2d');
            if (financeChart) {
                financeChart.destroy();
            }

            financeChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: ['Officiel', 'En attente', 'Estimé'],
                    datasets: [{
                        label: 'Chiffre d’affaires',
                        data: [official, pending, estimated],
                        borderColor: '#2a221b',
                        backgroundColor: 'rgba(42, 34, 27, 0.08)',
                        fill: true,
                        tension: 0.5,
                        cubicInterpolationMode: 'monotone',
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#2a221b',
                        borderWidth: 2.5,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            labels: {
                                color: '#4a3f37',
                                font: { weight: 'bold' }
                            }
                        },
                    },
                    scales: {
                        y: {
                            ticks: {
                                color: chartTextColor,
                                callback: value => `${Number(value).toLocaleString()} Ar`
                            },
                            grid: { color: chartGridColor, drawBorder: false }
                        },
                        x: {
                            ticks: { color: chartTextColor, font: { weight: 'bold' } },
                            grid: { display: false }
                        }
                    }
                }
            });
        }

        function forceLogoutOnLeave() {
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            if (!token) return;
            const payload = new FormData();
            payload.append('_token', token);
            navigator.sendBeacon('{{ route('dashboard.logout') }}', payload);
        }

        document.addEventListener('DOMContentLoaded', function() {
            const now = new Date();
            const today = now.toISOString().substring(0, 10);
            document.getElementById('global-date').value = today;

            document.getElementById('settings-trigger').addEventListener('click', function(event) {
                event.stopPropagation();
                toggleSettingsPanel();
            });

            document.addEventListener('click', function(event) {
                const panel = document.getElementById('settings-panel');
                const trigger = document.getElementById('settings-trigger');
                if (!panel.contains(event.target) && !trigger.contains(event.target)) {
                    toggleSettingsPanel(false);
                }
            });

            refreshAll();
        });

        window.addEventListener('pagehide', forceLogoutOnLeave);
        window.addEventListener('beforeunload', forceLogoutOnLeave);
    </script>
</body>
</html>
