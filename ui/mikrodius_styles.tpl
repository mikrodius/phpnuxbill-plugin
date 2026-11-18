{literal}
<style>.mk-brand-mark svg{width:100%;height:100%}</style>
<style>
/* Scoped tokens use the Mikrodius workspace palette without changing PHPNuxBill's shell. */
.mk-ui { --mk-surface:#fff; --mk-elevated:#f7f7f8; --mk-border:#e4e4e7; --mk-strong-border:#a1a1aa; --mk-text:#0a0a0b; --mk-muted:#52525b; --mk-primary:#2563eb; --mk-hover:#1d4ed8; --mk-on-primary:#fff; --mk-soft:#eff4ff; --mk-success:#15803d; --mk-success-bg:#f0fdf4; --mk-warning:#92400e; --mk-warning-bg:#fffbeb; --mk-danger:#b91c1c; --mk-danger-bg:#fef2f2; --mk-shadow:0 8px 24px rgba(0,0,0,.12); max-width:1240px; margin:0 auto; color:var(--mk-text); font:15px/1.6 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
.dark-mode .mk-ui { --mk-surface:#18181b; --mk-elevated:#202023; --mk-border:#3f3f46; --mk-strong-border:#71717a; --mk-text:#fafafa; --mk-muted:#c4c4cc; --mk-primary:#60a5fa; --mk-hover:#93c5fd; --mk-on-primary:#0a0a0b; --mk-soft:#17263e; --mk-success:#86efac; --mk-success-bg:#163323; --mk-warning:#fde68a; --mk-warning-bg:#342b16; --mk-danger:#fca5a5; --mk-danger-bg:#391d21; --mk-shadow:0 8px 24px rgba(0,0,0,.5); }
.content-wrapper:has(.mk-ui) > .content-header { display:none; }
.mk-ui * { box-sizing:border-box; }
.mk-ui a { color:var(--mk-primary); }
.mk-ui a:focus-visible,.mk-ui button:focus-visible,.mk-ui input:focus-visible,.mk-ui select:focus-visible,.mk-ui summary:focus-visible { outline:3px solid var(--mk-primary); outline-offset:2px; }

/* Header and Tools menu */
.mk-topbar { display:flex; justify-content:space-between; align-items:center; padding:12px 0 16px; gap:16px; }
.mk-title { display:flex; align-items:center; gap:10px; min-width:0; }
.mk-brand { display:flex; align-items:center; gap:10px; color:var(--mk-text)!important; font-size:18px; font-weight:650; text-decoration:none!important; line-height:1.3; }
.mk-brand-mark { width:28px; height:28px; flex-shrink:0; color:var(--mk-primary); }
.mk-crumb { color:var(--mk-muted); }
.mk-crumb-page { color:var(--mk-muted); font-size:15px; font-weight:550; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.mk-advanced { position:relative; flex-shrink:0; }
.mk-advanced > summary { display:flex; align-items:center; gap:8px; min-height:44px; padding:8px 14px; border:1px solid var(--mk-border); border-radius:7px; background:var(--mk-surface); color:var(--mk-text); font-size:14px; font-weight:550; cursor:pointer; list-style:none; }
.mk-advanced > summary::-webkit-details-marker { display:none; }
.mk-advanced > summary::after { content:""; width:7px; height:7px; border-right:2px solid currentColor; border-bottom:2px solid currentColor; transform:translateY(-2px) rotate(45deg); transition:transform .15s; }
.mk-advanced[open] > summary::after { transform:translateY(2px) rotate(-135deg); }
.mk-advanced > summary:hover { background:var(--mk-elevated); }
.mk-advanced-links { position:absolute; top:calc(100% + 6px); right:0; min-width:220px; padding:6px; border:1px solid var(--mk-border); border-radius:10px; background:var(--mk-surface); box-shadow:var(--mk-shadow); z-index:30; }
.mk-advanced-links a { display:flex; align-items:center; padding:10px 12px; min-height:44px; color:var(--mk-text); border-radius:6px; text-decoration:none; }
.mk-advanced-links a:hover,.mk-advanced-links a[aria-current] { background:var(--mk-elevated); text-decoration:none; }
.mk-advanced-links a[aria-current] { font-weight:600; }
.mk-version { display:block; padding:8px 12px 4px; margin-top:4px; border-top:1px solid var(--mk-border); color:var(--mk-muted); font-size:12px; }

/* Type */
.mk-page-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:24px; margin-bottom:24px; }
.mk-ui h2 { font-size:24px; line-height:1.3; letter-spacing:-.4px; font-weight:650; margin:0 0 8px; }
.mk-ui h3 { font-size:17px; line-height:1.4; font-weight:600; margin:0 0 4px; }
.mk-ui h4 { color:var(--mk-text); }
.mk-ui p { margin:0 0 16px; }
.mk-eyebrow { font-size:12px; text-transform:uppercase; letter-spacing:1.1px; font-weight:650; color:var(--mk-muted); margin:0 0 10px; }
.mk-muted,.mk-ui .text-muted,.mk-ui .help-block { color:var(--mk-muted)!important; }

/* Surfaces */
.mk-card,.mk-ui .panel { border:1px solid var(--mk-border); border-radius:12px; background:var(--mk-surface); box-shadow:none; margin-bottom:24px; overflow:hidden; color:var(--mk-text); }
.mk-card-body,.mk-ui .panel-body { padding:24px; }
.mk-section-header,.mk-ui .panel-heading { display:flex; justify-content:space-between; align-items:center; gap:16px; padding:16px 24px; border-bottom:1px solid var(--mk-border); background:var(--mk-surface)!important; color:var(--mk-text)!important; font-weight:600; }
.mk-section-header p { margin:0; font-size:14px; font-weight:400; color:var(--mk-muted); }
.mk-section-header form { margin:0; flex-shrink:0; }
.mk-divider { border-top:1px solid var(--mk-border); margin:24px 0; }

/* Status pills: the dot keeps the state readable without relying on colour alone. */
.mk-pill { display:inline-flex; align-items:center; gap:6px; padding:3px 10px; border-radius:999px; background:var(--mk-elevated); color:var(--mk-muted); font-size:13px; font-weight:550; line-height:1.5; white-space:nowrap; }
.mk-pill::before { content:""; width:7px; height:7px; border-radius:50%; background:currentColor; opacity:.8; }
.mk-pill.good { background:var(--mk-success-bg); color:var(--mk-success); }
.mk-pill.pending { background:var(--mk-warning-bg); color:var(--mk-warning); }
.mk-pill.bad { background:var(--mk-danger-bg); color:var(--mk-danger); }

/* Controls */
.mk-ui .btn { border-radius:7px; font-size:14px; font-weight:550; min-height:44px; padding:10px 16px; box-shadow:none; transition:background-color .15s,border-color .15s; white-space:normal; }
.mk-ui .btn-primary { background:var(--mk-primary); border-color:var(--mk-primary); color:var(--mk-on-primary); }
.mk-ui .btn-primary:hover,.mk-ui .btn-primary:focus { background:var(--mk-hover); border-color:var(--mk-hover); color:var(--mk-on-primary); }
.mk-ui .btn-default { color:var(--mk-text); border-color:var(--mk-border); background:var(--mk-surface); }
.mk-ui .btn-default:hover { background:var(--mk-elevated); border-color:var(--mk-strong-border); }
.mk-ui .btn:disabled,.mk-ui .btn[disabled] { opacity:.65; cursor:progress; }
.mk-ui .btn-link { color:var(--mk-primary); }
.mk-ui .form-control { min-height:44px; border-radius:7px; border:1px solid var(--mk-border); background:var(--mk-surface); color:var(--mk-text); box-shadow:none; font-size:15px; }
.mk-ui .form-control:hover:not(:disabled) { border-color:var(--mk-strong-border); }
.mk-ui .form-control:focus { border-color:var(--mk-primary); box-shadow:none; }
.mk-ui .form-control:disabled { background:var(--mk-elevated); color:var(--mk-muted); cursor:not-allowed; }
.mk-ui .form-control[aria-invalid="true"] { border-color:var(--mk-danger); }
.mk-ui .form-control::placeholder { color:var(--mk-muted); opacity:.8; }
.mk-ui label,.mk-ui .mk-label-text { font-size:14px; font-weight:600; margin:0 0 6px; color:var(--mk-text); }
.mk-connect-card label,.mk-detail-content label { display:block; }
.mk-ui label.mk-check { display:flex; align-items:flex-start; gap:10px; font-weight:400; margin:12px 0; }
.mk-ui label.mk-check input { margin-top:5px; flex-shrink:0; }
.mk-ui input[type=checkbox] { accent-color:var(--mk-primary); width:16px; height:16px; }
.mk-ui .help-block { font-size:13px; line-height:1.55; margin:6px 0 0; }
.mk-ui .form-group { margin-bottom:20px; }
.mk-ui form[aria-busy="true"] { cursor:progress; }

/* Tables */
.mk-ui .table { color:var(--mk-text); margin-bottom:0; font-size:14px; }
.mk-ui .table > thead > tr > th { color:var(--mk-muted); background:var(--mk-elevated); border:0; border-bottom:1px solid var(--mk-border); font-size:12px; font-weight:600; padding:10px 24px; }
.mk-ui .table > tbody > tr > td,.mk-ui .table > tbody > tr > th { padding:16px 24px; vertical-align:top; border-color:var(--mk-border); background:var(--mk-surface); }
.mk-ui .table small { font-size:13px; }
.mk-ui code { background:var(--mk-elevated); color:var(--mk-text); word-break:break-all; border-radius:4px; }

/* PHPNuxBill alerts and labels inside the plugin */
.mk-ui .alert { border:1px solid var(--mk-border); border-radius:8px; padding:14px 18px; }
.mk-ui .alert-warning { color:var(--mk-warning)!important; background:var(--mk-warning-bg)!important; }
.mk-ui .alert-danger { color:var(--mk-danger)!important; background:var(--mk-danger-bg)!important; }
.mk-ui .alert-success { color:var(--mk-success)!important; background:var(--mk-success-bg)!important; }
.mk-ui .label { display:inline-block; padding:5px 8px; font-size:12px; border-radius:5px; }
.mk-ui .label-success { color:var(--mk-success); background:var(--mk-success-bg); }
.mk-ui .label-danger { color:var(--mk-danger); background:var(--mk-danger-bg); }
.mk-ui .label-warning { color:var(--mk-warning); background:var(--mk-warning-bg); }
.mk-ui .label-info,.mk-ui .label-default { color:var(--mk-muted); background:var(--mk-elevated); }

/* Inline feedback, shown under the control it belongs to */
.mk-feedback { display:flex; flex-direction:column; gap:2px; margin:12px 0 0; padding:10px 14px; border-left:3px solid currentColor; border-radius:0 7px 7px 0; font-size:14px; line-height:1.5; }
.mk-feedback strong { font-weight:600; }
.mk-feedback small { font-size:12px; opacity:.85; }
.mk-feedback.is-error { color:var(--mk-danger); background:var(--mk-danger-bg); }
.mk-feedback.is-success { color:var(--mk-success); background:var(--mk-success-bg); }
.mk-section-feedback { padding:0 24px 16px; }
.mk-section-feedback .mk-feedback { margin-top:16px; }

/* Connection panel */
.mk-connect-card { max-width:680px; margin-bottom:12px; }
.mk-connect-card .mk-section-header h2 { font-size:18px; letter-spacing:-.2px; margin:0; }
.mk-connect-card form { margin:0; }
.mk-label-row { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:baseline; gap:4px 12px; margin-bottom:6px; }
.mk-label-row label { margin:0; }
.mk-saved-key { color:var(--mk-muted); font-size:13px; }
.mk-saved-key code { padding:1px 6px; font-size:12px; }
.mk-inline-field { display:flex; align-items:stretch; gap:8px; }
.mk-inline-field .form-control { flex:1; min-width:0; }
.mk-inline-field .btn { flex-shrink:0; }
.mk-ui .mk-empty-line { margin:0; color:var(--mk-muted); font-size:14px; }

/* Disclosures */
.mk-ui details.mk-disclosure { margin-top:12px; }
.mk-ui details.mk-disclosure > summary,.mk-workspace-details > summary { display:inline-flex; align-items:center; gap:8px; min-height:44px; padding:8px 0; color:var(--mk-primary); font-size:14px; font-weight:550; cursor:pointer; list-style:none; }
.mk-ui details.mk-disclosure > summary::-webkit-details-marker,.mk-workspace-details > summary::-webkit-details-marker { display:none; }
.mk-ui details.mk-disclosure > summary::before,.mk-workspace-details > summary::before { content:""; width:7px; height:7px; border-right:2px solid currentColor; border-bottom:2px solid currentColor; transform:rotate(-45deg); transition:transform .15s; flex-shrink:0; }
.mk-ui details.mk-disclosure[open] > summary::before,.mk-workspace-details[open] > summary::before { transform:rotate(45deg); }
.mk-ui .mk-disclosure form { margin-top:8px; }
.mk-ui .mk-disclosure form + form { margin-top:20px; padding-top:20px; border-top:1px solid var(--mk-border); }
.mk-create { padding-top:4px; }
.mk-workspace-details { max-width:680px; }
.mk-workspace-details > summary { color:var(--mk-text); }
.mk-workspace-details[open] { max-width:none; }
.mk-detail-content { padding-top:8px; }
.mk-detail-content > .mk-feedback { margin:0 0 16px; }
.mk-stats-line { display:flex; flex-wrap:wrap; gap:8px 24px; margin-bottom:16px; color:var(--mk-muted); font-size:14px; }
.mk-stats-line strong { color:var(--mk-text); font-weight:600; }

/* Router rows */
.mk-row-detail { display:block; color:var(--mk-muted); font-size:13px; margin-top:4px; }
.mk-row-actions { display:flex; flex-wrap:wrap; align-items:center; gap:8px; }
.mk-row-actions form { margin:0; }
.mk-empty { padding:40px 24px; text-align:center; }
.mk-progress { padding:12px 24px; border-bottom:1px solid var(--mk-border); background:var(--mk-soft); font-size:14px; font-weight:550; }

@media(max-width:560px) {
    .mk-inline-field { flex-direction:column; gap:8px; }
    .mk-inline-field .btn { width:100%; }
}
@media(max-width:767px) {
    .mk-ui { font-size:14px; }
    .mk-page-heading { flex-direction:column; gap:12px; }
    .mk-ui h2 { font-size:22px; }
    .mk-section-header { flex-wrap:wrap; padding:14px 16px; }
    .mk-card-body,.mk-ui .panel-body { padding:16px; }
    .mk-section-feedback { padding:0 16px 16px; }
    .mk-progress { padding:12px 16px; }
    .mk-crumb,.mk-crumb-page { display:none; }
    .mk-ui .table>tbody>tr>td,.mk-ui .table>thead>tr>th { padding:12px 16px; }
}
@media(prefers-reduced-motion:reduce) { .mk-ui *,.mk-ui *::before,.mk-ui *::after { transition:none!important; animation:none!important; } }
</style>
{/literal}
