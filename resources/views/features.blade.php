<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>gp-cami · Features &amp; Capabilities</title>
<style>
  :root{
    --navy:#00284c; --navy-deep:#001a33; --orange:#f47d27; --gold:#c9a227; --gold-light:#f0d98a;
    --gold-grad:linear-gradient(135deg,#a67c00,#e9c766 45%,#c9a227 70%,#f3e3a6);
    --ink:#212121; --ink-soft:#4f4f4f; --ink-faint:#7d858e; --surface:#fff; --surface-2:#f6f7f9;
    --line:#e1e1e1; --bg:#f2f2f2; --ok:#2f7d54; --ok-wash:#e5f1ea; --crit:#b34534; --crit-wash:#f5e2de;
    --warn:#b1791f; --warn-wash:#f6ecd6;
    --sans:"Open Sans","Segoe UI",Helvetica,Arial,sans-serif; --mono:ui-monospace,"SF Mono",Menlo,Consolas,monospace;
  }
  @media (prefers-color-scheme:dark){:root{
    --ink:#e7ebf0; --ink-soft:#aeb7c2; --ink-faint:#7c8794; --surface:#131b26; --surface-2:#0f1620;
    --line:#23303f; --bg:#0b1017; --navy:#0d2c4a; --ok:#5fbd85; --ok-wash:#16251c; --crit:#de6f5d; --crit-wash:#2c1a16;
  }}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);font-family:var(--sans);font-size:15px;line-height:1.6;-webkit-font-smoothing:antialiased}
  a{color:var(--orange);text-decoration:none}
  code{font-family:var(--mono);font-size:.85em;background:var(--surface-2);border:1px solid var(--line);border-radius:3px;padding:1px 5px}
  .wrap{max-width:1060px;margin:0 auto;padding:0 24px}
  /* topbar */
  .topbar{background:linear-gradient(90deg,var(--navy),var(--navy-deep));border-bottom:4px solid var(--orange)}
  .topbar .wrap{display:flex;align-items:center;justify-content:space-between;padding:16px 24px}
  .brand{color:#fff;font-weight:800;font-size:1.15rem}
  .brand b{background:var(--gold-grad);-webkit-background-clip:text;background-clip:text;color:transparent}
  .topnav a{color:#cdd8e4;font-size:.9rem;margin-left:20px;padding-bottom:2px;border-bottom:2px solid transparent}
  .topnav a.active,.topnav a:hover{color:#fff;border-bottom-color:var(--gold)}
  .topnav a.btn{background:var(--orange);color:#fff;border-radius:6px;padding:7px 14px;font-weight:700;border-bottom:none}
  .topnav a.btn:hover{background:#ff9648;border-bottom:none}
  /* hero */
  .hero{background:linear-gradient(180deg,var(--navy),var(--navy-deep));color:#fff;padding:44px 0 40px;position:relative;overflow:hidden}
  .hero::after{content:"";position:absolute;left:0;right:0;bottom:0;height:4px;background:var(--gold-grad)}
  .hero h1{font-size:2.3rem;font-weight:800;letter-spacing:-.02em;margin:0}
  .hero h1 .g{background:var(--gold-grad);-webkit-background-clip:text;background-clip:text;color:transparent}
  .hero p{color:#cdd8e4;max-width:62ch;margin:12px 0 0;font-size:1.05rem}
  .flow{display:flex;flex-wrap:wrap;gap:8px;margin-top:22px}
  .flow span{font-family:var(--mono);font-size:12px;color:#cdd8e4;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.16);border-radius:4px;padding:6px 11px}
  .flow b{color:var(--gold-light)}
  /* stats */
  .stats{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin:-28px 0 0;position:relative;z-index:2}
  .stat{background:var(--surface);border:1px solid var(--line);border-top:3px solid var(--gold);border-radius:8px;padding:14px}
  .stat .n{font-size:1.7rem;font-weight:800;color:var(--navy);line-height:1}
  @media (prefers-color-scheme:dark){.stat .n{color:var(--gold-light)}}
  .stat .l{font-family:var(--mono);font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-faint);margin-top:6px}
  section{padding:36px 0;border-bottom:1px solid var(--line)}
  h2{font-size:1.4rem;font-weight:800;color:var(--navy);margin:0 0 6px}
  @media (prefers-color-scheme:dark){h2{color:#cdd8e4}}
  .eyebrow{font-family:var(--mono);font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--orange);font-weight:700;margin:0 0 6px}
  p.sub{color:var(--ink-soft);max-width:70ch;margin:0 0 18px}
  .cards{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
  .card{background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:18px 18px 16px;position:relative}
  .card::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;background:var(--gold-grad);border-radius:10px 10px 0 0}
  .card h3{margin:6px 0 6px;font-size:1.02rem;color:var(--navy)}
  @media (prefers-color-scheme:dark){.card h3{color:#e7ebf0}}
  .card p{margin:0;font-size:.88rem;color:var(--ink-soft)}
  .card .ico{font-family:var(--mono);font-size:12px;font-weight:700;color:#4a3a05;background:var(--gold-grad);border-radius:5px;padding:3px 8px;display:inline-block}
  .badge{display:inline-block;font-family:var(--mono);font-size:10px;font-weight:700;padding:2px 7px;border-radius:999px;margin-left:6px;vertical-align:middle}
  .badge.live{color:var(--ok);background:var(--ok-wash)} .badge.soon{color:var(--warn);background:var(--warn-wash)}
  .pipe{display:flex;flex-wrap:wrap;gap:0;margin-top:8px;align-items:stretch}
  .pipe .step{flex:1;min-width:150px;background:var(--surface);border:1px solid var(--line);border-left:3px solid var(--gold);border-radius:8px;padding:12px 14px;margin:6px}
  .pipe .step .k{font-family:var(--mono);font-size:11px;color:var(--orange);font-weight:700}
  .pipe .step h4{margin:4px 0 4px;font-size:.92rem}
  .pipe .step p{margin:0;font-size:.82rem;color:var(--ink-soft)}
  table{border-collapse:collapse;width:100%;font-size:.86rem;border:1px solid var(--line);border-radius:8px;overflow:hidden}
  th{background:var(--navy);color:#fff;text-align:left;padding:9px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.05em}
  td{padding:9px 12px;border-top:1px solid var(--line);color:var(--ink-soft)}
  tr:nth-child(even) td{background:var(--surface-2)}
  .k-list{list-style:none;padding:0;margin:8px 0 0;display:grid;grid-template-columns:1fr 1fr;gap:8px}
  .k-list li{background:var(--surface);border:1px solid var(--line);border-left:3px solid var(--gold);border-radius:6px;padding:9px 12px;font-size:.86rem}
  .k-list .kn{font-family:var(--mono);color:var(--navy);font-weight:700}
  @media (prefers-color-scheme:dark){.k-list .kn{color:var(--gold-light)}}
  /* process diagrams */
  .flowd{background:var(--surface);border:1px solid var(--line);border-top:3px solid var(--gold);border-radius:10px;padding:18px 20px;margin:14px 0;overflow-x:auto}
  .flowd .cap{font-family:var(--mono);font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-faint);margin:0 0 14px}
  .frow{display:flex;align-items:center;flex-wrap:nowrap;min-width:max-content}
  .fnode{background:var(--surface-2);border:1px solid var(--line);border-radius:8px;padding:9px 13px;text-align:center;min-width:104px}
  .fnode .t{font-weight:700;color:var(--navy);display:block;font-size:.84rem}
  @media (prefers-color-scheme:dark){.fnode .t{color:#e7ebf0}}
  .fnode .d{color:var(--ink-faint);font-size:.7rem;font-family:var(--mono);display:block;margin-top:2px}
  .fnode.src{border-top:3px solid var(--navy)} .fnode.store{border-top:3px solid var(--gold)}
  .fnode.out{border-top:3px solid var(--orange)} .fnode.dec{background:var(--warn-wash);border-color:var(--warn)}
  .farrow{color:var(--gold);font-weight:800;padding:0 9px;font-size:1.05rem;flex:0 0 auto}
  .fstack{display:flex;flex-direction:column;gap:7px}
  .flabel{font-family:var(--mono);font-size:10px;color:var(--ink-faint);margin-right:6px;flex:0 0 auto}
  .fnode.mini{min-width:0;padding:6px 10px;font-size:.78rem}
  .fnode.ok{border-left:3px solid var(--ok)} .fnode.rev{border-left:3px solid var(--warn)} .fnode.new{border-left:3px solid var(--ink-faint)} .fnode.no{border-left:3px solid var(--crit)}
  footer{padding:26px 0 46px;color:var(--ink-faint);font-family:var(--mono);font-size:12px}
  @media (max-width:900px){.cards{grid-template-columns:1fr 1fr}.stats{grid-template-columns:repeat(3,1fr)}.k-list{grid-template-columns:1fr}}
  @media (max-width:560px){.cards,.stats{grid-template-columns:1fr}}
</style>
</head>
<body>
  <div class="topbar"><div class="wrap">
    <div class="brand">gp&#8209;<b>cami</b></div>
    <nav class="topnav"><a href="/features" class="active">Features</a><a href="/docs">API Docs</a><a href="/search">Identity Search</a></nav>
  </div></div>

  <header class="hero"><div class="wrap">
    <h1>Golden <span class="g">Profile</span> — capabilities</h1>
    <p>A source-agnostic identity hub that resolves scattered person records into one golden identity,
    enriches it, remembers how matches were resolved, and serves it to CAMI over a REST API.</p>
    <div class="flow">
      <span><b>1</b> Collect</span><span><b>2</b> Clean</span><span><b>3</b> Match</span>
      <span><b>4</b> Merge</span><span><b>5</b> Serve</span>
    </div>
  </div></header>

  <div class="wrap">
    <div class="stats">
      @if(!isset($stats['error']))
      <div class="stat"><div class="n">{{ $stats['identities'] }}</div><div class="l">Identities</div></div>
      <div class="stat"><div class="n">{{ $stats['source_rows'] }}</div><div class="l">Source links</div></div>
      <div class="stat"><div class="n">{{ $stats['profiles'] }}</div><div class="l">Profiles</div></div>
      <div class="stat"><div class="n">{{ $stats['licenses'] }}</div><div class="l">Licenses</div></div>
      <div class="stat"><div class="n">{{ $stats['exclusions'] }}</div><div class="l">Exclusions</div></div>
      <div class="stat"><div class="n">{{ $stats['audit'] }}</div><div class="l">Survivorship logs</div></div>
      @else
      <div class="stat" style="grid-column:1/-1"><div class="l">hub unavailable: {{ $stats['error'] }}</div></div>
      @endif
    </div>
  </div>

  <div class="wrap">

    <section>
      <p class="eyebrow">Core capabilities</p>
      <h2>What gp-cami does</h2>
      <p class="sub">Everything below runs today against the local hub unless marked otherwise.</p>
      <div class="cards">
        <div class="card"><span class="ico">01</span><h3>Identity resolution <span class="badge live">live</span></h3><p>Two-pass matcher binds each raw source row to exactly one golden identity — deterministic keys first, probabilistic scoring for the rest.</p></div>
        <div class="card"><span class="ico">02</span><h3>Cross-account scope <span class="badge live">live</span></h3><p>The same person under many clients resolves to one identity — collapsing duplicates across every account.</p></div>
        <div class="card"><span class="ico">03</span><h3>Per-field survivorship <span class="badge live">live</span></h3><p>Each field's winner is chosen by source authority + recency; every decision logged to a survivorship audit trail.</p></div>
        <div class="card"><span class="ico">04</span><h3>Enrichment rollups <span class="badge live">live</span></h3><p>Aliases, licenses, addresses, credential matches, and exclusion hits roll up to the identity with full provenance.</p></div>
        <div class="card"><span class="ico">05</span><h3>Resolution reuse <span class="badge live">live</span></h3><p>A decision made once — under any account — is keyed to the identity and re-applied to later matches for the same person.</p></div>
        <div class="card"><span class="ico">06</span><h3>Pinned decisions <span class="badge live">live</span></h3><p>A human review can pin a link so the engine never re-matches it — locked, reversible, auditable.</p></div>
        <div class="card"><span class="ico">07</span><h3>Materialized profile <span class="badge live">live</span></h3><p>One denormalized wide row per identity — the full record in a single indexed read, rebuilt from the graph.</p></div>
        <div class="card"><span class="ico">08</span><h3>REST API for CAMI <span class="badge live">live</span></h3><p>Two Sanctum-authed endpoints: name search, and credential resolution with qualifying-status filtering.</p></div>
        <div class="card"><span class="ico">09</span><h3>SSN-safe matching <span class="badge live">live</span></h3><p>Matches on <code>sha512(ssn+key)</code>, stores ciphertext for parity, never returns SSN — <code>ssn_last_four</code> only.</p></div>
        <div class="card"><span class="ico">10</span><h3>Board actions <span class="badge soon">schema ready</span></h3><p>Append-only disciplinary facts, never overwritten. Table + rollup in place; awaiting a board-action source.</p></div>
        <div class="card"><span class="ico">11</span><h3>Multi-source hub <span class="badge soon">1 of 8</span></h3><p>New source = one connector + a config row, no engine change. streamline_local live; NPPES/LEIE/SAM/state next.</p></div>
        <div class="card"><span class="ico">12</span><h3>Read-only source <span class="badge live">live</span></h3><p>The engine holds <code>SELECT</code>-only on every source and writes only the hub — enforced in code, no FK points at a source.</p></div>
        <div class="card"><span class="ico">13</span><h3>Parallel backfill <span class="badge live">live</span></h3><p>Load partitions across N workers (per-worker resume cursor), then <code>gp:dedup</code> merges cross-partition duplicates and finalize runs sharded — scales the one-time backlog near-linearly.</p></div>
        <div class="card"><span class="ico">14</span><h3>Resumable &amp; batched <span class="badge live">live</span></h3><p>Chunked keyset paging checkpoints after every chunk — stop and restart continues where it left off. Source lookups and rollups batch per chunk; materialization is deferred to one pass.</p></div>
      </div>
    </section>

    <section>
      <p class="eyebrow">Matching engine</p>
      <h2>How records resolve</h2>
      <div class="pipe">
        <div class="step"><div class="k">CONNECT</div><h4>Stage</h4><p>Source rows mapped into one canonical <code>stg_person</code> shape (+ alias/address/license). Engine never sees source schema.</p></div>
        <div class="step"><div class="k">PASS A</div><h4>Deterministic</h4><p>Exact high-precision keys bind instantly: ssn_hash, npi, dea, upin, license+state, name+dob.</p></div>
        <div class="step"><div class="k">PASS B</div><h4>Probabilistic</h4><p>Block, then weighted score (Jaro-Winkler name, dob, address, exclusion share). Auto-merge / review / new bands.</p></div>
        <div class="step"><div class="k">GATE</div><h4>Hard-no</h4><p>Conflicting DOB or two different valid NPIs block any merge, however similar.</p></div>
        <div class="step"><div class="k">MERGE</div><h4>Survivorship</h4><p>Per-field winner by authority + recency; profile re-materialized per affected identity.</p></div>
      </div>
      <h3 style="margin-top:22px">Deterministic keys (Pass A)</h3>
      <ul class="k-list">
        <li><span class="kn">ssn_hash</span> — 0.99</li>
        <li><span class="kn">npi</span> — 0.99</li>
        <li><span class="kn">dea_number</span> — 0.99</li>
        <li><span class="kn">upin</span> — 0.99</li>
        <li><span class="kn">license_number + certification_state</span> — 0.99</li>
        <li><span class="kn">name + dob</span> — 0.95</li>
      </ul>
    </section>

    <section>
      <p class="eyebrow">How it works</p>
      <h2>The golden profile process</h2>
      <p class="sub">From raw source rows to an answered identity search — extraction, staging, resolution,
      survivorship, sync, and serving. Each diagram scrolls sideways on small screens.</p>

      <h3>1 · End-to-end pipeline</h3>
      <div class="flowd">
        <p class="cap">Collect → clean → match → merge → serve</p>
        <div class="frow">
          <div class="fnode src"><span class="t">Sources</span><span class="d">streamline_local (+8 planned)</span></div>
          <span class="farrow">&rsaquo;</span>
          <div class="fnode"><span class="t">Connector</span><span class="d">clean &amp; map</span></div>
          <span class="farrow">&rsaquo;</span>
          <div class="fnode store"><span class="t">stg_person</span><span class="d">canonical staging</span></div>
          <span class="farrow">&rsaquo;</span>
          <div class="fnode"><span class="t">Resolution</span><span class="d">Pass A + Pass B</span></div>
          <span class="farrow">&rsaquo;</span>
          <div class="fnode"><span class="t">Survivorship</span><span class="d">per-field winner</span></div>
          <span class="farrow">&rsaquo;</span>
          <div class="fnode store"><span class="t">gp_identity_profile</span><span class="d">wide golden row</span></div>
          <span class="farrow">&rsaquo;</span>
          <div class="fnode out"><span class="t">API / Search</span><span class="d">→ CAMI</span></div>
        </div>
      </div>

      <h3>2 · Data extraction &amp; staging</h3>
      <div class="flowd">
        <p class="cap">gp:backfill — chunked keyset paging, batched source reads, resumable, read-only source</p>
        <div class="frow">
          <div class="fnode src"><span class="t">employees</span><span class="d">+ alt_* columns</span></div>
          <span class="farrow">&rsaquo;</span>
          <div class="fnode"><span class="t">StreamlineLocalConnector</span><span class="d">trim / normalize / resolve account</span></div>
          <span class="farrow">&rsaquo;</span>
          <div class="fstack">
            <div class="fnode store mini"><span class="t">stg_person</span></div>
            <div class="fnode store mini"><span class="t">stg_person_alias</span></div>
            <div class="fnode store mini"><span class="t">stg_person_address</span></div>
            <div class="fnode store mini"><span class="t">stg_person_license</span></div>
          </div>
          <span class="farrow">&rsaquo;</span>
          <div class="fnode"><span class="t">block_key</span><span class="d">soundex(last)+dob_yr</span></div>
        </div>
      </div>

      <h3>3 · Resolution &amp; calculation</h3>
      <div class="flowd">
        <p class="cap">Two passes; hard-no safeguards; then survivorship + re-materialize</p>
        <div class="frow">
          <div class="fnode store"><span class="t">staged record</span></div>
          <span class="farrow">&rsaquo;</span>
          <div class="fnode dec"><span class="t">Pass A</span><span class="d">deterministic keys</span></div>
          <span class="farrow">&rsaquo;</span>
          <div class="fstack">
            <div class="frow"><span class="flabel">hit</span><div class="fnode mini ok"><span class="t">bind identity</span></div></div>
            <div class="frow"><span class="flabel">miss</span><div class="fnode dec mini"><span class="t">Pass B: block → score</span></div></div>
          </div>
          <span class="farrow">&rsaquo;</span>
          <div class="fstack">
            <div class="fnode mini ok"><span class="t">≥ 0.92 auto-match</span></div>
            <div class="fnode mini rev"><span class="t">0.75–0.92 review</span></div>
            <div class="fnode mini new"><span class="t">&lt; 0.75 new identity</span></div>
            <div class="fnode mini no"><span class="t">hard-no: dob/NPI conflict → blocked</span></div>
          </div>
          <span class="farrow">&rsaquo;</span>
          <div class="fnode"><span class="t">Survivorship</span><span class="d">authority + recency</span></div>
          <span class="farrow">&rsaquo;</span>
          <div class="fnode store"><span class="t">profile rebuilt</span></div>
        </div>
      </div>

      <h3>Pass B scoring, in detail</h3>
      <p class="sub">Pass B runs only when Pass A finds no exact key. It never brute-forces every pair —
      it compares within a block, gates each candidate behind the name rule and hard-no safeguards,
      then sums weighted signals into a single 0–1 score.</p>

      <h4 style="margin:18px 0 6px;font-size:.95rem">Step 1 — Blocking</h4>
      <p class="sub" style="margin-bottom:12px">Candidates are limited to identities that share the record's
      <code>block_key</code> = <code>soundex(last_name) + birth-year</code> (aliases included). Comparing only
      within a block keeps it fast; an unusually large block is flagged for a steward rather than silently
      truncated (cap {{ $passb['block_size_cap'] ?? 2000 }}).</p>

      <h4 style="margin:14px 0 6px;font-size:.95rem">Step 2 — Gates (a candidate must pass both)</h4>
      <ul class="k-list" style="grid-template-columns:1fr">
        <li><span class="kn">Name prerequisite</span> — first + last must be equal; middle compatible (null, or initial-vs-full first letter, or exact); suffix equal if both present; DOB compatible. Fail ⇒ candidate skipped.</li>
        <li><span class="kn">Hard-no</span> — a conflicting DOB (different birth year) <em>or</em> two different valid NPIs discards the candidate outright, no matter how similar everything else is.</li>
      </ul>

      <h4 style="margin:16px 0 6px;font-size:.95rem">Step 3 — Weighted score</h4>
      <p class="sub" style="margin-bottom:10px">Each matching signal adds its weight; the total is capped at 1.0.
      Weights are the fallback defaults — in production they're calibrated per source-pair from labeled data.</p>
      <table>
        <tr><th>Signal</th><th>Weight</th><th>Fires when</th></tr>
        <tr><td>Name similarity</td><td class="num">{{ $passb['weights']['name'] ?? '—' }}</td><td>Jaro-Winkler over <code>"last first"</code> (×similarity, so a perfect name = full weight).</td></tr>
        <tr><td>Date of birth</td><td class="num">{{ $passb['weights']['dob'] ?? '—' }}</td><td>Full weight on exact date; half on same year only.</td></tr>
        <tr><td>Address</td><td class="num">{{ $passb['weights']['address'] ?? '—' }}</td><td>Any staged address line1 + zip matches any of the identity's addresses (mailing / practice / alt).</td></tr>
        <tr><td>Zip</td><td class="num">{{ $passb['weights']['zip'] ?? '—' }}</td><td>Shared zip (even if the street differs).</td></tr>
        <tr><td>Exclusion share</td><td class="num">{{ $passb['weights']['exclusion_share'] ?? '—' }}</td><td>The identity already carries an exclusion registry (compliance signal).</td></tr>
        <tr><td>Provider type</td><td class="num">{{ $passb['weights']['provider_type'] ?? '—' }}</td><td><span class="badge soon">reserved</span> — awaits provider-type / NPPES data; not yet scored.</td></tr>
      </table>

      <h4 style="margin:16px 0 6px;font-size:.95rem">Step 4 — Bands</h4>
      <div class="frow" style="flex-wrap:wrap;gap:8px">
        <div class="fnode mini ok"><span class="t">≥ {{ $passb['auto_merge_at'] ?? '0.92' }} → auto-match</span><span class="d">merge, no human</span></div>
        <div class="fnode mini rev"><span class="t">{{ $passb['review_band_floor'] ?? '0.75' }}–{{ $passb['auto_merge_at'] ?? '0.92' }} → review</span><span class="d">bind + flag steward</span></div>
        <div class="fnode mini new"><span class="t">&lt; {{ $passb['review_band_floor'] ?? '0.75' }} → new identity</span><span class="d">no match</span></div>
      </div>

      <h4 style="margin:18px 0 6px;font-size:.95rem">Worked example</h4>
      <div class="flowd">
        <p class="cap">Same name + exact DOB + shared exclusion registry, no address on file</p>
        <div class="frow" style="flex-wrap:wrap;gap:6px">
          <div class="fnode mini"><span class="t">name 1.0 × {{ $passb['weights']['name'] ?? 0.45 }}</span><span class="d">= {{ $passb['weights']['name'] ?? 0.45 }}</span></div>
          <span class="farrow">+</span>
          <div class="fnode mini"><span class="t">dob exact</span><span class="d">= {{ $passb['weights']['dob'] ?? 0.20 }}</span></div>
          <span class="farrow">+</span>
          <div class="fnode mini"><span class="t">exclusion share</span><span class="d">= {{ $passb['weights']['exclusion_share'] ?? 0.07 }}</span></div>
          <span class="farrow">=</span>
          <div class="fnode mini new"><span class="t">{{ number_format(($passb['weights']['name'] ?? 0.45) + ($passb['weights']['dob'] ?? 0.20) + ($passb['weights']['exclusion_share'] ?? 0.07), 2) }}</span><span class="d">&lt; {{ $passb['review_band_floor'] ?? 0.75 }} → new identity</span></div>
        </div>
      </div>
      <p class="sub">This is deliberately conservative — precision over recall for compliance. On the current
      single-source data, Pass A already resolves every non-distinct record, so Pass B mostly defers to a new
      identity until richer signals (NPPES addresses, provider type) and calibrated per-pair weights raise
      borderline cases into the merge band.</p>

      <h3>4 · Incremental sync</h3>
      <div class="flowd">
        <p class="cap">gp:sync — only what changed since the watermark</p>
        <div class="frow">
          <div class="fnode store"><span class="t">gp_watermark</span><span class="d">last cursor</span></div>
          <span class="farrow">&rsaquo;</span>
          <div class="fnode src"><span class="t">changed rows</span><span class="d">date_modified &gt; watermark</span></div>
          <span class="farrow">&rsaquo;</span>
          <div class="fnode"><span class="t">re-stage</span></div>
          <span class="farrow">&rsaquo;</span>
          <div class="fnode"><span class="t">re-resolve</span><span class="d">Pass A / B</span></div>
          <span class="farrow">&rsaquo;</span>
          <div class="fnode store"><span class="t">re-materialize</span></div>
          <span class="farrow">&rsaquo;</span>
          <div class="fnode"><span class="t">advance watermark</span></div>
        </div>
      </div>

      <h3>5 · Identity search</h3>
      <div class="flowd">
        <p class="cap">One indexed read of the materialized profile — no joins, no SSN exposure</p>
        <div class="frow">
          <div class="fnode out"><span class="t">CAMI / dashboard</span><span class="d">last + first name</span></div>
          <span class="farrow">&rsaquo;</span>
          <div class="fnode"><span class="t">match</span><span class="d">canonical + aliases</span></div>
          <span class="farrow">&rsaquo;</span>
          <div class="fnode store"><span class="t">gp_identity_profile</span><span class="d">PK / name index</span></div>
          <span class="farrow">&rsaquo;</span>
          <div class="fnode out"><span class="t">results</span><span class="d">ssn_last_four only</span></div>
        </div>
      </div>
    </section>

    <section>
      <p class="eyebrow">Run modes</p>
      <h2>Commands &amp; operations</h2>
      <table>
        <tr><th>Command</th><th>Mode</th><th>What it does</th></tr>
        <tr><td><code>gp:backfill</code></td><td>Mode 1</td><td>Resolve every source record — the one-time backlog. Keyset-paged, resumable (per-segment cursor), idempotent. Deferred materialization by default. Options: <code>--from-id</code>/<code>--to-id</code> (partition), <code>--segment</code> (parallel worker), <code>--no-finalize</code>, <code>--finalize-only --shard=i --shards=N</code> (sharded materialize), <code>--restart</code>.</td></tr>
        <tr><td><code>gp:dedup</code></td><td>Mode 1</td><td>Merge identities that share a deterministic key (ssn/npi/upin/dea/license/name+dob) — run after parallel load to fold cross-partition duplicates. Idempotent; shardable via <code>--shard</code>/<code>--shards</code> with row-locked merges.</td></tr>
        <tr><td><code>gp:sync</code></td><td>Mode 2</td><td>Incremental — only rows changed since the watermark. Idempotent re-runs.</td></tr>
        <tr><td><code>gp:rebuild-profile</code></td><td>Serving</td><td>Re-materialize <code>gp_identity_profile</code> for one identity or all.</td></tr>
      </table>
      <h3 style="margin-top:22px">Parallel backfill pipeline</h3>
      <p class="sub" style="margin-bottom:10px">For the one-time backlog at scale, <code>scripts/backfill-parallel.sh</code> orchestrates the whole run co-located with the hub: N id-partitioned load workers → sharded <code>gp:dedup</code> + a serial mop-up pass → sharded finalize. Each stage is resumable and idempotent.</p>
      <div class="pipe">
        <div class="step"><div class="k">FAN OUT</div><h4>Load workers</h4><p>N workers over disjoint id ranges, each with its own resume cursor (<code>--segment</code>), materialization deferred.</p></div>
        <div class="step"><div class="k">DEDUP</div><h4>Merge duplicates</h4><p>Hash-partitioned shards merge identities that share a key across partitions; a final serial pass converges transitive merges.</p></div>
        <div class="step"><div class="k">FINALIZE</div><h4>Sharded materialize</h4><p>Survivorship + profile rebuild split across N shards by <code>identity_id</code>.</p></div>
      </div>
    </section>

    <section>
      <p class="eyebrow">Data model</p>
      <h2>What gets stored</h2>
      <p class="sub">A relational graph: one identity node, evidence/attribute rows, one-to-many collections, and a denormalized read model — {{ $stats['model_tables'] ?? '17+' }} <code>gp_*</code>/<code>stg_*</code> tables on the <code>golden_profile</code> hub.</p>
      <table>
        <tr><th>Table</th><th>Holds</th></tr>
        <tr><td><code>gp_identity</code></td><td>One resolved real person; canonical keys.</td></tr>
        <tr><td><code>gp_source_link</code></td><td>Each source row bound to one identity; match_state incl. pinned.</td></tr>
        <tr><td><code>gp_license</code> / <code>gp_address</code></td><td>One-to-many licenses (state/board) and addresses.</td></tr>
        <tr><td><code>gp_identity_credential</code> / <code>gp_identity_exclusion</code></td><td>Credential + exclusion rollups with link_state candidates.</td></tr>
        <tr><td><code>gp_board_action</code></td><td>Append-only disciplinary actions.</td></tr>
        <tr><td><code>gp_identity_resolution</code></td><td>Reusable, cross-account resolution decisions.</td></tr>
        <tr><td><code>gp_attribute</code> / <code>gp_survivorship_audit</code></td><td>Per-field provenance and the winner-selection audit trail.</td></tr>
        <tr><td><code>gp_identity_profile</code></td><td>Denormalized wide row — the full golden record.</td></tr>
      </table>
    </section>

    <section>
      <p class="eyebrow">Interface</p>
      <h2>REST API</h2>
      <p class="sub">JSON in/out, Sanctum bearer auth, 120 req/min. Full reference on the <a href="/docs">API Docs</a> page.</p>
      <table>
        <tr><th>Endpoint</th><th>Purpose</th></tr>
        <tr><td><code>POST /api/v1/identity-search</code></td><td>Name-only search → every matching identity with all associated data (aliases, licenses, exclusions, resolutions).</td></tr>
        <tr><td><code>POST /api/v1/credential-search</code></td><td>Resolve one person → latest qualifying credential match + prior resolution.</td></tr>
      </table>
    </section>

    <footer>gp-cami · Golden Profile · features rendered from the live hub on {{ $generatedAt }} · <a href="/docs">API Docs →</a></footer>
  </div>
</body>
</html>
