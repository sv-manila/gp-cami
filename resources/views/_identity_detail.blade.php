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
