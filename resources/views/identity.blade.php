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
  details summary{cursor:pointer;color:var(--orange);font-size:.8rem;font-family:var(--mono)}
  pre{background:var(--surface-2);border:1px solid var(--line);border-radius:6px;padding:8px 10px;margin:6px 0 0;font-family:var(--mono);font-size:.74rem;line-height:1.45;max-height:300px;overflow:auto;white-space:pre-wrap;word-break:break-word;max-width:520px}
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
    @include('_identity_detail')
    <footer>gp-cami · Golden Profile · identity #{{ $p->identity_id }} · rebuilt {{ $p->profile_built_at }}</footer>
  </div>
</body>
</html>
