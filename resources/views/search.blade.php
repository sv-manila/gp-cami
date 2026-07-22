<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>gp-cami · Search</title>
<style>
  :root{
    --navy:#00284c; --navy-deep:#001a33; --orange:#f47d27; --gold:#c9a227; --gold-light:#f0d98a;
    --gold-grad:linear-gradient(135deg,#a67c00,#e9c766 45%,#c9a227 70%,#f3e3a6);
    --ink:#212121; --ink-soft:#4f4f4f; --ink-faint:#7d858e; --surface:#fff; --surface-2:#f6f7f9;
    --line:#e1e1e1; --bg:#f2f2f2; --ok:#2f7d54; --ok-wash:#e5f1ea; --crit:#b34534; --crit-wash:#f5e2de;
    --sans:"Open Sans","Segoe UI",Helvetica,Arial,sans-serif; --mono:ui-monospace,"SF Mono",Menlo,Consolas,monospace;
  }
  @media (prefers-color-scheme:dark){:root{
    --ink:#e7ebf0; --ink-soft:#aeb7c2; --ink-faint:#7c8794; --surface:#131b26; --surface-2:#0f1620;
    --line:#23303f; --bg:#0b1017; --navy:#0d2c4a; --ok:#5fbd85; --ok-wash:#16251c; --crit:#de6f5d; --crit-wash:#2c1a16;
  }}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);font-family:var(--sans);font-size:15px;line-height:1.6;-webkit-font-smoothing:antialiased}
  a{color:var(--orange);text-decoration:none}
  code{font-family:var(--mono);font-size:.85em}
  .wrap{max-width:1060px;margin:0 auto;padding:0 24px}
  .topbar{background:linear-gradient(90deg,var(--navy),var(--navy-deep));border-bottom:4px solid var(--orange)}
  .topbar .wrap{display:flex;align-items:center;justify-content:space-between;padding:16px 24px}
  .brand{color:#fff;font-weight:800;font-size:1.15rem}
  .brand b{background:var(--gold-grad);-webkit-background-clip:text;background-clip:text;color:transparent}
  .topnav{display:flex;align-items:center;gap:20px}
  .topnav a{color:#cdd8e4;font-size:.9rem;padding-bottom:2px;border-bottom:2px solid transparent}
  .topnav a.active,.topnav a:hover{color:#fff;border-bottom-color:var(--gold)}
  .topnav a.btn{background:var(--orange);color:#fff;border-radius:6px;padding:7px 14px;font-weight:700;border-bottom:none}
  .topnav a.btn:hover{background:#ff9648}
  h1{font-size:1.7rem;font-weight:800;letter-spacing:-.02em;margin:30px 0 4px}
  p.sub{color:var(--ink-soft);margin:0 0 20px}
  form.search{display:flex;gap:10px;flex-wrap:wrap;background:var(--surface);border:1px solid var(--line);border-top:3px solid var(--gold);border-radius:10px;padding:16px;margin-bottom:24px}
  form.search .fld{display:flex;flex-direction:column;flex:1;min-width:180px}
  form.search label{font-family:var(--mono);font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-faint);margin-bottom:4px}
  form.search input{padding:10px 12px;border:1px solid var(--line);border-radius:7px;background:var(--bg);color:var(--ink);font-size:.95rem}
  form.search input:focus{outline:2px solid var(--orange);border-color:var(--orange)}
  form.search button{align-self:flex-end;background:var(--orange);color:#fff;font-weight:700;border:none;border-radius:7px;padding:11px 22px;cursor:pointer;font-size:.95rem}
  form.search button:hover{background:#ff9648}
  .count{font-family:var(--mono);font-size:12px;color:var(--ink-faint);margin-bottom:10px}
  table{border-collapse:collapse;width:100%;font-size:.88rem;border:1px solid var(--line);border-radius:10px;overflow:hidden}
  th{background:var(--navy);color:#fff;text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.05em}
  td{padding:10px 12px;border-top:1px solid var(--line);color:var(--ink-soft);vertical-align:top}
  tr:nth-child(even) td{background:var(--surface-2)}
  td.name{color:var(--navy);font-weight:700}
  @media (prefers-color-scheme:dark){td.name{color:#e7ebf0}}
  .num{font-variant-numeric:tabular-nums;text-align:right}
  .pill{display:inline-block;font-family:var(--mono);font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px}
  .pill.crit{color:var(--crit);background:var(--crit-wash)} .pill.ok{color:var(--ok);background:var(--ok-wash)}
  .empty{background:var(--surface);border:1px dashed var(--line);border-radius:10px;padding:40px;text-align:center;color:var(--ink-faint)}
  .chips{display:flex;flex-wrap:wrap;gap:4px}
  .chip{font-family:var(--mono);font-size:11px;background:var(--surface-2);border:1px solid var(--line);border-radius:4px;padding:1px 6px;color:var(--ink-soft)}
  footer{padding:26px 0 46px;color:var(--ink-faint);font-family:var(--mono);font-size:12px}
</style>
</head>
<body>
  <div class="topbar"><div class="wrap">
    <div class="brand">gp&#8209;<b>cami</b></div>
    <nav class="topnav">
      <a href="/features">Features</a>
      <a href="/docs">API Docs</a>
      <a href="/search" class="btn active">Search</a>
    </nav>
  </div></div>

  <div class="wrap">
    <h1>Identity search</h1>
    <p class="sub">Search the golden profiles by name. Matches canonical names and aliases (maiden names hit too).</p>

    <form class="search" method="get" action="/search">
      <div class="fld">
        <label for="last">Last name</label>
        <input id="last" name="last" value="{{ $last }}" placeholder="e.g. Adkins" autofocus required>
      </div>
      <div class="fld">
        <label for="first">First name <span style="text-transform:none">(optional)</span></label>
        <input id="first" name="first" value="{{ $first }}" placeholder="e.g. Paula">
      </div>
      <button type="submit">Search</button>
    </form>

    @if($last === '')
      <div class="empty">Enter a last name to search the golden profiles.</div>
    @elseif($results->isEmpty())
      <div class="empty">No golden profiles match <strong>{{ $last }}</strong>{{ $first ? ' / '.$first : '' }}.</div>
    @else
      <div class="count">{{ $results->count() }} result{{ $results->count() === 1 ? '' : 's' }}@if($results->count() === 50) (showing first 50)@endif</div>
      <table>
        <thead><tr>
          <th>ID</th><th>Name</th><th>DOB</th><th>SSN</th>
          <th class="num">Recs</th><th class="num">Accts</th><th>Licenses</th>
          <th>Exclusions</th><th>Board</th>
        </tr></thead>
        <tbody>
        @foreach($results as $r)
          <tr>
            <td><code>#{{ $r->identity_id }}</code></td>
            <td class="name">{{ trim(($r->last_name ?? '').', '.($r->first_name ?? '').' '.($r->middle_name ?? '')) }}{{ $r->suffix ? ' '.$r->suffix : '' }}</td>
            <td>{{ $r->date_of_birth ? $r->date_of_birth->toDateString() : '—' }}</td>
            <td>{{ $r->ssn_last_four ? '•••-••-'.$r->ssn_last_four : '—' }}</td>
            <td class="num">{{ $r->record_count }}</td>
            <td class="num">{{ $r->account_count }}</td>
            <td>
              @if(($r->licenses ?? []) === [])—@else
                <div class="chips">@foreach(array_slice($r->licenses, 0, 4) as $l)<span class="chip">{{ $l['number'] ?? '?' }}{{ !empty($l['state']) ? ' ('.$l['state'].')' : '' }}</span>@endforeach</div>
              @endif
            </td>
            <td>
              @if($r->has_active_exclusion)<span class="pill crit">{{ $r->exclusion_count }} active</span>
              @elseif($r->exclusion_count)<span class="pill">{{ $r->exclusion_count }}</span>@else—@endif
            </td>
            <td>@if($r->has_active_board_action)<span class="pill crit">{{ $r->board_action_count }}</span>@elseif($r->board_action_count)<span class="pill">{{ $r->board_action_count }}</span>@else—@endif</td>
          </tr>
        @endforeach
        </tbody>
      </table>
    @endif

    <footer>gp-cami · Golden Profile · served from <code>gp_identity_profile</code> · <a href="/docs">API Docs →</a></footer>
  </div>
</body>
</html>
