<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>gp-cami · Identity #{{ $p->identity_id }}</title>
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
    --line:#23303f; --bg:#0b1017; --navy:#0d2c4a; --ok:#5fbd85; --ok-wash:#16251c; --crit:#de6f5d; --crit-wash:#2c1a16; --warn:#d7a445; --warn-wash:#2a2211;
  }}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);font-family:var(--sans);font-size:15px;line-height:1.6;-webkit-font-smoothing:antialiased}
  a{color:var(--orange);text-decoration:none}
  code{font-family:var(--mono);font-size:.85em;background:var(--surface-2);border:1px solid var(--line);border-radius:3px;padding:1px 5px}
  .wrap{max-width:1060px;margin:0 auto;padding:0 24px}
  .topbar{background:linear-gradient(90deg,var(--navy),var(--navy-deep));border-bottom:4px solid var(--orange)}
  .topbar .wrap{display:flex;align-items:center;justify-content:space-between;padding:16px 24px}
  .brand{color:#fff;font-weight:800;font-size:1.15rem}
  .brand b{background:var(--gold-grad);-webkit-background-clip:text;background-clip:text;color:transparent}
  .topnav a{color:#cdd8e4;font-size:.9rem;margin-left:20px;padding-bottom:2px;border-bottom:2px solid transparent}
  .topnav a:hover{color:#fff;border-bottom-color:var(--gold)}
  .crumb{margin:22px 0 0;font-size:.85rem;color:var(--ink-faint)}
  .head{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin:6px 0 4px}
  h1{font-size:1.9rem;font-weight:800;letter-spacing:-.02em;margin:0}
  .uuid{font-family:var(--mono);font-size:12px;color:var(--ink-faint)}
  .badges{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 0}
  .pill{display:inline-block;font-family:var(--mono);font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px}
  .pill.crit{color:var(--crit);background:var(--crit-wash)} .pill.ok{color:var(--ok);background:var(--ok-wash)}
  .pill.warn{color:var(--warn);background:var(--warn-wash)} .pill.info{color:#fff;background:var(--navy)}
  .pill.mut{color:var(--ink-soft);background:var(--surface-2);border:1px solid var(--line)}
  h2{font-size:1.15rem;font-weight:800;color:var(--navy);margin:34px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--line)}
  h2 .n{font-family:var(--mono);font-size:.8rem;color:var(--ink-faint);font-weight:400}
  @media (prefers-color-scheme:dark){h2{color:#cdd8e4}}
  .grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
  .fld{background:var(--surface);border:1px solid var(--line);border-radius:8px;padding:10px 12px}
  .fld .k{font-family:var(--mono);font-size:10px;letter-spacing:.05em;text-transform:uppercase;color:var(--ink-faint)}
  .fld .v{font-weight:600;margin-top:2px;word-break:break-word}
  table{border-collapse:collapse;width:100%;font-size:.85rem;border:1px solid var(--line);border-radius:8px;overflow:hidden;margin-top:4px}
  th{background:var(--navy);color:#fff;text-align:left;padding:9px 11px;font-size:10px;text-transform:uppercase;letter-spacing:.05em}
  td{padding:8px 11px;border-top:1px solid var(--line);color:var(--ink-soft);vertical-align:top}
  tr:nth-child(even) td{background:var(--surface-2)}
  .chips{display:flex;flex-wrap:wrap;gap:5px}
  .chip{font-family:var(--mono);font-size:11px;background:var(--surface-2);border:1px solid var(--line);border-radius:4px;padding:2px 7px;color:var(--ink-soft)}
  .empty{color:var(--ink-faint);font-size:.88rem;font-style:italic;padding:6px 0}
  .yes{color:var(--ok);font-weight:700} .no{color:var(--ink-faint)}
  footer{padding:26px 0 46px;color:var(--ink-faint);font-family:var(--mono);font-size:12px}
  @media (max-width:820px){.grid{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>
  <div class="topbar"><div class="wrap">
    <div class="brand">gp&#8209;<b>cami</b></div>
    <nav class="topnav"><a href="/features">Features</a><a href="/docs">API Docs</a><a href="/search">Identity Search</a></nav>
  </div></div>

  <div class="wrap">
    <div class="crumb"><a href="/search">← Identity Search</a></div>
    <div class="head">
      <h1>{{ trim(($p->last_name ?? '').', '.($p->first_name ?? '').' '.($p->middle_name ?? '')) }}{{ $p->suffix ? ' '.$p->suffix : '' }}</h1>
      <span class="pill info">#{{ $p->identity_id }}</span>
    </div>
    <div class="uuid">{{ $p->identity_uuid }}</div>
    <div class="badges">
      <span class="pill mut">confidence {{ rtrim(rtrim(number_format((float)$p->confidence,4),'0'),'.') }}</span>
      <span class="pill mut">{{ $p->record_count }} source records</span>
      <span class="pill mut">{{ $p->account_count }} account(s)</span>
      <span class="pill mut">{{ $p->system_count }} system(s)</span>
      @if($p->has_active_exclusion)<span class="pill crit">active exclusion</span>@endif
      @if($p->has_active_board_action)<span class="pill crit">active board action</span>@endif
      @if($p->terminated)<span class="pill warn">terminated</span>@endif
    </div>

    <h2>Identity</h2>
    <div class="grid">
      <div class="fld"><div class="k">First</div><div class="v">{{ $p->first_name ?: '—' }}</div></div>
      <div class="fld"><div class="k">Middle</div><div class="v">{{ $p->middle_name ?: '—' }}</div></div>
      <div class="fld"><div class="k">Last</div><div class="v">{{ $p->last_name ?: '—' }}</div></div>
      <div class="fld"><div class="k">Suffix</div><div class="v">{{ $p->suffix ?: '—' }}</div></div>
      <div class="fld"><div class="k">Date of birth</div><div class="v">{{ $p->date_of_birth ? $p->date_of_birth->toDateString() : '—' }}</div></div>
      <div class="fld"><div class="k">SSN</div><div class="v">{{ $p->ssn_last_four ? '•••-••-'.$p->ssn_last_four : '—' }}</div></div>
      <div class="fld"><div class="k">NPI</div><div class="v">{{ $p->npi ?: '—' }}</div></div>
      <div class="fld"><div class="k">UPIN</div><div class="v">{{ $p->upin ?: '—' }}</div></div>
      <div class="fld"><div class="k">DEA</div><div class="v">{{ $p->dea_number ?: '—' }}</div></div>
      <div class="fld"><div class="k">Primary address</div><div class="v">{{ trim(($p->address1 ?? '').' '.($p->city ?? '').' '.($p->state ?? '').' '.($p->zip ?? '')) ?: '—' }}</div></div>
    </div>

    <h2>Aliases <span class="n">{{ count($p->aliases ?? []) }}</span></h2>
    @if(($p->aliases ?? []) === [])<div class="empty">No aliases.</div>@else
    <div class="chips">@foreach($p->aliases as $a)<span class="chip">{{ $a['type'] ?? '' }}: {{ trim(($a['first'] ?? '').' '.($a['last'] ?? '')) ?: '—' }}</span>@endforeach</div>
    @endif

    <h2>Licenses <span class="n">{{ count($p->licenses ?? []) }}</span></h2>
    @if(($p->licenses ?? []) === [])<div class="empty">No licenses.</div>@else
    <table><thead><tr><th>Number</th><th>State</th><th>Board</th><th>Type</th><th>Registry</th><th>Verified</th></tr></thead><tbody>
      @foreach($p->licenses as $l)<tr><td><code>{{ $l['number'] ?? '—' }}</code></td><td>{{ $l['state'] ?? '—' }}</td><td>{{ $l['board'] ?? '—' }}</td><td>{{ $l['type'] ?? '—' }}</td><td>{{ $l['registry'] ?? '—' }}</td><td>{!! ($l['verified'] ?? false) ? '<span class=yes>yes</span>' : '<span class=no>no</span>' !!}</td></tr>@endforeach
    </tbody></table>@endif

    <h2>Addresses <span class="n">{{ count($p->addresses ?? []) }}</span></h2>
    @if(($p->addresses ?? []) === [])<div class="empty">No addresses.</div>@else
    <table><thead><tr><th>Type</th><th>Address</th><th>City</th><th>State</th><th>Zip</th></tr></thead><tbody>
      @foreach($p->addresses as $a)<tr><td>{{ $a['type'] ?? '' }}</td><td>{{ trim(($a['address1'] ?? '').' '.($a['address2'] ?? '')) ?: '—' }}</td><td>{{ $a['city'] ?? '—' }}</td><td>{{ $a['state'] ?? '—' }}</td><td>{{ $a['zip'] ?? '—' }}</td></tr>@endforeach
    </tbody></table>@endif

    <h2>Credential matches <span class="n">{{ $creds->count() }}</span></h2>
    @if($creds->isEmpty())<div class="empty">No credential matches rolled up to this identity.</div>@else
    <table><thead><tr><th>Match ID</th><th>Registry</th><th>Status</th><th>Code</th><th>Valid</th><th>Current</th><th>Link</th><th>Resolved</th></tr></thead><tbody>
      @foreach($creds as $c)<tr>
        <td><code>{{ $c->credential_match_id }}</code></td><td>{{ $c->registry ?: '—' }}</td>
        <td>{{ $c->match_summary_status ?: '—' }}</td><td>{{ $c->match_summary_status_code ?? '—' }}</td>
        <td>{!! $c->match_is_valid ? '<span class=yes>yes</span>' : '<span class=no>no</span>' !!}</td>
        <td>{!! $c->current ? '<span class=yes>yes</span>' : '<span class=no>no</span>' !!}</td>
        <td><span class="pill mut">{{ $c->link_state }}</span></td>
        <td>{{ $c->date_resolved ?: '—' }}</td>
      </tr>@endforeach
    </tbody></table>@endif

    <h2>Exclusions <span class="n">{{ $excl->count() }}</span></h2>
    @if($excl->isEmpty())<div class="empty">No exclusion hits.</div>@else
    <table><thead><tr><th>Match ID</th><th>Registry</th><th>Match flags</th><th>Link state</th></tr></thead><tbody>
      @foreach($excl as $e)
        @php $flags = collect(['SSN'=>$e->is_ssn_match,'NPI'=>$e->is_npi_match,'Name'=>$e->is_canonical_name_match,'UPIN'=>$e->is_upin_match,'License'=>$e->is_license_number_match])->filter()->keys(); @endphp
        <tr>
          <td><code>{{ $e->match_id }}</code></td>
          <td>{{ $e->registry ?: '—' }}</td>
          <td>@if($flags->isEmpty())—@else<div class="chips">@foreach($flags as $f)<span class="chip">{{ $f }}</span>@endforeach</div>@endif</td>
          <td><span class="pill {{ $e->link_state === 'rejected' ? 'mut' : 'warn' }}">{{ $e->link_state }}</span></td>
        </tr>
      @endforeach
    </tbody></table>@endif

    <h2>Board actions <span class="n">{{ $board->count() }}</span></h2>
    @if($board->isEmpty())<div class="empty">No board actions.</div>@else
    <table><thead><tr><th>Registry</th><th>Action</th><th>Action date</th><th>Resolved</th></tr></thead><tbody>
      @foreach($board as $b)<tr><td>{{ $b->registry ?: '—' }}</td><td>{{ $b->action_type ?: '—' }}</td><td>{{ $b->action_date ?: '—' }}</td><td>{{ $b->resolution_date ?: '—' }}</td></tr>@endforeach
    </tbody></table>@endif

    <h2>Resolutions <span class="n">{{ $res->count() }}</span></h2>
    @if($res->isEmpty())<div class="empty">No reusable resolution decisions.</div>@else
    <table><thead><tr><th>Domain</th><th>Target key</th><th>Decision</th><th>By</th><th>At</th><th>Auto-reuse</th></tr></thead><tbody>
      @foreach($res as $r)<tr><td>{{ $r->domain }}</td><td><code>{{ $r->target_key }}</code></td><td>{{ $r->decision }}</td><td>{{ $r->resolved_by ?? '—' }}</td><td>{{ $r->resolved_at }}</td><td>{!! $r->is_auto_resolvable ? '<span class=yes>yes</span>' : '<span class=no>no</span>' !!}</td></tr>@endforeach
    </tbody></table>@endif

    <h2>Source records &amp; match links <span class="n">{{ $links->count() }}</span></h2>
    <table><thead><tr><th>System</th><th>Table</th><th>Source ID</th><th>Account</th><th>Method</th><th>Key</th><th>Score</th><th>State</th></tr></thead><tbody>
      @foreach($links as $l)<tr>
        <td><code>#{{ $l->system_id }}</code></td><td>{{ $l->source_table }}</td><td><code>{{ $l->source_id }}</code></td>
        <td>{{ $l->account_id ?? '—' }}</td><td>{{ $l->match_method }}</td><td><code>{{ $l->match_key ?: '—' }}</code></td>
        <td>{{ rtrim(rtrim(number_format((float)$l->match_score,4),'0'),'.') }}</td>
        <td><span class="pill {{ $l->is_pinned ? 'info' : 'mut' }}">{{ $l->is_pinned ? 'pinned' : $l->match_state }}</span></td>
      </tr>@endforeach
    </tbody></table>

    <footer>gp-cami · Golden Profile · identity #{{ $p->identity_id }} · rebuilt {{ $p->profile_built_at }}</footer>
  </div>
</body>
</html>
