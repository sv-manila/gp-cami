<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>gp-cami · API Documentation</title>
<style>
  :root{
    --navy:#00284c; --navy-deep:#001a33; --orange:#f47d27; --gold:#c9a227; --gold-light:#f0d98a;
    --ink:#212121; --ink-soft:#4f4f4f; --ink-faint:#7d858e; --surface:#fff; --surface-2:#f6f7f9;
    --line:#e1e1e1; --bg:#f2f2f2; --ok:#2f7d54; --ok-wash:#e5f1ea; --crit:#b34534; --crit-wash:#f5e2de;
    --warn:#b1791f; --warn-wash:#f6ecd6; --info:#00284c; --info-wash:#e2eaf3;
    --sans:"Open Sans","Segoe UI",Helvetica,Arial,sans-serif; --mono:ui-monospace,"SF Mono",Menlo,Consolas,monospace;
  }
  @media (prefers-color-scheme:dark){:root{
    --ink:#e7ebf0; --ink-soft:#aeb7c2; --ink-faint:#7c8794; --surface:#131b26; --surface-2:#0f1620;
    --line:#23303f; --bg:#0b1017; --navy:#0d2c4a; --info:#7ea6d4; --info-wash:#12202f;
    --ok:#5fbd85; --ok-wash:#16251c; --crit:#de6f5d; --crit-wash:#2c1a16; --warn:#d7a445; --warn-wash:#2a2211;
  }}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);font-family:var(--sans);font-size:15px;line-height:1.6;-webkit-font-smoothing:antialiased}
  a{color:var(--orange);text-underline-offset:2px}
  code{font-family:var(--mono);font-size:.85em;background:var(--surface-2);border:1px solid var(--line);border-radius:3px;padding:1px 5px;color:var(--navy)}
  @media (prefers-color-scheme:dark){code{color:var(--gold-light)}}
  .layout{display:grid;grid-template-columns:250px 1fr;min-height:100vh}
  /* sidebar */
  aside{background:linear-gradient(180deg,var(--navy),var(--navy-deep));border-right:1px solid var(--line);padding:26px 20px;position:sticky;top:0;height:100vh;overflow-y:auto}
  aside .brand{color:#fff;font-weight:800;font-size:1.2rem;letter-spacing:-.02em}
  aside .brand b{background:linear-gradient(135deg,#a67c00,#e9c766 45%,#f3e3a6);-webkit-background-clip:text;background-clip:text;color:transparent}
  aside .tag{font-family:var(--mono);font-size:11px;color:#9fb2c6;margin-top:4px}
  aside nav{margin-top:26px;display:flex;flex-direction:column;gap:2px}
  aside nav a{color:#cdd8e4;text-decoration:none;font-size:.9rem;padding:7px 10px;border-radius:6px;border-left:2px solid transparent}
  aside nav a:hover{background:rgba(255,255,255,.06);border-left-color:var(--gold)}
  aside nav .grp{color:#7c8794;font-family:var(--mono);font-size:10px;letter-spacing:.1em;text-transform:uppercase;margin:16px 0 4px;padding-left:10px}
  main{padding:40px 48px;max-width:900px}
  h1{font-size:2rem;font-weight:800;letter-spacing:-.02em;margin:0 0 6px}
  h2{font-size:1.35rem;font-weight:800;color:var(--navy);margin:44px 0 12px;padding-bottom:8px;border-bottom:1px solid var(--line);scroll-margin-top:20px}
  @media (prefers-color-scheme:dark){h2{color:var(--gold-light)}}
  h3{font-size:1rem;margin:24px 0 8px}
  p{color:var(--ink-soft);max-width:68ch}
  .lede{font-size:1.05rem}
  table{border-collapse:collapse;width:100%;font-size:.88rem;margin:12px 0;border:1px solid var(--line);border-radius:8px;overflow:hidden}
  th{background:var(--navy);color:#fff;text-align:left;padding:9px 12px;font-size:11px;letter-spacing:.05em;text-transform:uppercase}
  td{padding:9px 12px;border-top:1px solid var(--line);color:var(--ink-soft);vertical-align:top}
  tr:nth-child(even) td{background:var(--surface-2)}
  pre{background:var(--surface);border:1px solid var(--line);border-left:3px solid var(--gold);border-radius:8px;padding:14px 16px;overflow-x:auto;font-family:var(--mono);font-size:.82rem;line-height:1.5;color:var(--ink)}
  pre .c{color:var(--ink-faint)}
  .verb{font-family:var(--mono);font-weight:700;color:#fff;background:var(--orange);border-radius:4px;padding:2px 9px;font-size:.8rem;letter-spacing:.05em}
  .path{font-family:var(--mono);font-weight:700;color:var(--navy);font-size:1rem}
  @media (prefers-color-scheme:dark){.path{color:#cdd8e4}}
  .endpoint{background:var(--surface);border:1px solid var(--line);border-top:3px solid var(--navy);border-radius:10px;padding:18px 22px;margin:16px 0;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
  .pill{display:inline-block;font-family:var(--mono);font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px;white-space:nowrap}
  .pill.ok{color:var(--ok);background:var(--ok-wash)} .pill.crit{color:var(--crit);background:var(--crit-wash)}
  .pill.warn{color:var(--warn);background:var(--warn-wash)} .pill.info{color:#fff;background:var(--navy)}
  .req{color:var(--crit);font-weight:700;font-size:.75rem;font-family:var(--mono)}
  .opt{color:var(--ink-faint);font-size:.75rem;font-family:var(--mono)}
  .note{background:var(--warn-wash);border:1px solid var(--warn);border-left:3px solid var(--warn);border-radius:8px;padding:12px 16px;margin:16px 0;font-size:.9rem}
  .note b{color:var(--warn)}
  .statusgrid{display:flex;flex-wrap:wrap;gap:6px;margin:10px 0}
  footer{margin-top:56px;padding-top:20px;border-top:1px solid var(--line);color:var(--ink-faint);font-family:var(--mono);font-size:12px}
  @media (max-width:820px){.layout{grid-template-columns:1fr}aside{position:static;height:auto}main{padding:28px 20px}}
</style>
</head>
<body>
<div class="layout">
  <aside>
    <div class="brand">gp&#8209;<b>cami</b></div>
    <div class="tag">Golden Profile API v1</div>
    <nav>
      <span class="grp">Site</span>
      <a href="/features">← Features &amp; capabilities</a>
      <a href="/search">Identity Search</a>
      <span class="grp">Overview</span>
      <a href="#intro">Introduction</a>
      <a href="#base">Base URL</a>
      <a href="#auth">Authentication</a>
      <a href="#rate">Rate limiting</a>
      <a href="#errors">Errors</a>
      <span class="grp">Endpoints</span>
      <a href="#identity-search">identity-search</a>
      <a href="#credential-search">credential-search</a>
      <span class="grp">Reference</span>
      <a href="#status-filter">Qualifying status</a>
      <a href="#pii">PII &amp; SSN</a>
    </nav>
  </aside>

  <main>
    <h1>Golden Profile API</h1>
    <p class="lede">Resolve scattered person records into one golden identity, and serve it — with
    credentials, exclusions, and prior resolutions — to CAMI over two REST endpoints.</p>

    <h2 id="intro">Introduction</h2>
    <p>The Golden Profile hub (<code>gp-cami</code>) collapses many raw records from
    <code>streamline_local</code> (and future sources) into one resolved identity per real person.
    All responses are JSON. All requests are <code>POST</code> with a JSON body.</p>

    <h2 id="base">Base URL</h2>
    <pre>{{ $baseUrl }}/api/v1</pre>
    <p>Local dev binds <code>0.0.0.0:8137</code>; a VM reaches the host at <code>http://192.168.56.1:8137</code>.</p>

    <h2 id="auth">Authentication</h2>
    <p>Bearer token (Laravel Sanctum). Send it on every request:</p>
    <pre>Authorization: Bearer &lt;token&gt;
Accept: application/json
Content-Type: application/json</pre>
    <p>Missing or invalid token returns <span class="pill crit">401</span>.</p>

    <h2 id="rate">Rate limiting</h2>
    <p>120 requests per minute per token. Exceeding it returns <span class="pill crit">429</span>.</p>

    <h2 id="errors">Errors</h2>
    <table>
      <tr><th>Status</th><th>Meaning</th></tr>
      <tr><td><span class="pill crit">401</span></td><td>Unauthenticated — missing/invalid bearer token.</td></tr>
      <tr><td><span class="pill warn">422</span></td><td>Validation failed — required field missing or malformed. Body lists errors.</td></tr>
      <tr><td><span class="pill crit">404</span></td><td>credential-search only — no identity resolved for the inputs.</td></tr>
      <tr><td><span class="pill crit">429</span></td><td>Rate limit exceeded.</td></tr>
    </table>
    <pre><span class="c">// 422 example</span>
{
  "message": "The registry field is required.",
  "errors": { "registry": ["The registry field is required."] }
}</pre>

    <h2 id="identity-search">POST /identity-search</h2>
    <div class="endpoint"><span class="verb">POST</span><span class="path">/api/v1/identity-search</span></div>
    <p>Name-only search. Returns every matching golden identity with all associated data, served from
    <code>gp_identity_profile</code>. Matches canonical name <em>and</em> aliases (maiden names still hit).</p>
    <h3>Request</h3>
    <table>
      <tr><th>Field</th><th>Type</th><th>Required</th><th>Notes</th></tr>
      <tr><td><code>last_name</code></td><td>string</td><td><span class="req">required</span></td><td>Case-insensitive.</td></tr>
      <tr><td><code>first_name</code></td><td>string</td><td><span class="opt">optional</span></td><td>Narrows results.</td></tr>
      <tr><td><code>page</code></td><td>int</td><td><span class="opt">optional</span></td><td>Default 1.</td></tr>
      <tr><td><code>per_page</code></td><td>int</td><td><span class="opt">optional</span></td><td>Default 25, max 100.</td></tr>
    </table>
    <h3>Example</h3>
    <pre>curl -X POST {{ $baseUrl }}/api/v1/identity-search \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"last_name":"Adkins","first_name":"Paula"}'</pre>
    <h3>Response <span class="pill ok">200</span></h3>
    <pre>{
  "data": [
    {
      "identity_id": 10,
      "identity_uuid": "a0108593-...",
      "first_name": "Paula", "middle_name": null, "last_name": "Adkins",
      "date_of_birth": "1975-04-12",
      "ssn_last_four": "6789",
      "npi": null, "upin": null, "dea_number": null,
      "primary_address": { "city": "...", "state": "..." },
      "terminated": false,
      "confidence": 1.0, "record_count": 8, "account_count": 1, "system_count": 1,
      "aliases": [ { "type": "maiden", "first": null, "last": "..." } ],
      "licenses": [ { "number": "...", "state": "CA", "type": "RN", "verified": false } ],
      "addresses": [], "accounts": [ 42 ],
      "source_records": [ { "system_code": "streamline_local", "source_table": "employees", "source_id": 123, "account_id": 42 } ],
      "credentials": [], "exclusions": [ { "match_id": 1, "registry": "...", "link_state": "candidate" } ],
      "has_active_exclusion": true,
      "resolutions": [],
      "last_updated": "2026-07-20T14:46:34+00:00"
    }
  ],
  "meta": { "total": 2, "page": 1, "per_page": 25, "last_page": 1 }
}</pre>
    <div class="note"><b>Never returns</b> <code>ssn_hash</code> or encrypted SSN — <code>ssn_last_four</code> only.</div>

    <h2 id="credential-search">POST /credential-search</h2>
    <div class="endpoint"><span class="verb">POST</span><span class="path">/api/v1/credential-search</span></div>
    <p>Resolve one person, then return the <b>latest qualifying credential match</b> plus any prior
    resolution for that credential.</p>
    <h3>Request</h3>
    <table>
      <tr><th>Field</th><th>Type</th><th>Required</th><th>Notes</th></tr>
      <tr><td><code>registry</code></td><td>string</td><td><span class="req">required</span></td><td>Verifying registry.</td></tr>
      <tr><td><code>first_name</code></td><td>string</td><td><span class="req">required</span></td><td></td></tr>
      <tr><td><code>last_name</code></td><td>string</td><td><span class="req">required</span></td><td></td></tr>
      <tr><td><code>license_number</code></td><td>string</td><td><span class="opt">optional</span></td><td>Narrows; enables <code>prior_resolution</code>.</td></tr>
      <tr><td><code>license_type</code></td><td>string</td><td><span class="opt">optional</span></td><td>e.g. RN.</td></tr>
      <tr><td><code>dob</code></td><td>date</td><td><span class="opt">optional</span></td><td>YYYY-MM-DD.</td></tr>
      <tr><td><code>ssn</code></td><td>string</td><td><span class="opt">optional</span></td><td>Exact plaintext; hashed to match, never stored/returned.</td></tr>
    </table>
    <h3>Example</h3>
    <pre>curl -X POST {{ $baseUrl }}/api/v1/credential-search \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"registry":"CA-BRN","first_name":"Paula","last_name":"Adkins","license_number":"RN123456"}'</pre>
    <h3>Response <span class="pill ok">200</span></h3>
    <pre>{
  "identity": {
    "identity_id": 10, "identity_uuid": "a0108593-...",
    "first_name": "Paula", "last_name": "Adkins", "ssn_last_four": "6789"
  },
  "match": {
    "credential_match_id": 99213, "registry": "CA-BRN",
    "match_summary_status": "Valid", "match_summary_status_code": 20,
    "match_is_valid": true, "current": true,
    "expiry_date": "2027-01-31", "date_resolved": "2026-05-01 09:12:00"
  },
  "prior_resolution": {
    "decision": "cleared", "action_type": "resolve",
    "resolved_by": 44, "resolved_at": "2026-04-02 10:00:00", "auto_resolvable": true
  }
}</pre>
    <p><code>match</code> is <code>null</code> (still <span class="pill info">200</span>) when the person has no
    qualifying, unexpired credential. <code>prior_resolution</code> is <code>null</code> when no prior decision exists.</p>
    <div class="note"><b>404</b> when no identity resolves. If more than one identity matches, the best by
    <code>record_count</code> is returned and a warning is logged.</div>

    <h2 id="status-filter">Qualifying status filter</h2>
    <p>credential-search returns only matches whose <code>match_summary_status_code</code> is qualifying
    (sourced from <code>MatchSummaryStatus</code>, <code>streamlineverify/sv</code>), and that are not past
    <code>expiry_date</code>.</p>
    <h3>Qualifying (returned)</h3>
    <div class="statusgrid">
      @foreach($qualifying as $code)<span class="pill ok">{{ $code }}</span>@endforeach
    </div>
    <h3>Excluded (filtered out)</h3>
    <div class="statusgrid">
      @foreach($excluded as $code)<span class="pill crit">{{ $code }}</span>@endforeach
    </div>

    <h2 id="pii">PII &amp; SSN handling</h2>
    <p>SSN input arrives as exact plaintext and is used only to resolve identity via a deterministic
    hash (<code>sha512(ssn + key)</code>) matching CAMI's scheme. It is stored encrypted (AES-256-CBC,
    non-deterministic) for parity, never compared as ciphertext, and <b>never returned</b> in any response
    — only <code>ssn_last_four</code>.</p>

    <footer>gp-cami · Golden Profile · API v1 · generated from live config on {{ $generatedAt }}</footer>
  </main>
</div>
</body>
</html>
