{include file="sections/header.tpl"}
{include file="mikrodius_nav.tpl"}
<style>
    .mk-step { border-left: 3px solid var(--mk-border); padding: 12px 16px; margin-bottom: 8px; background:var(--mk-elevated); border-radius:4px; }
    .mk-step.succeeded, .mk-step.skipped { border-color: var(--mk-success); }
    .mk-step.running, .mk-step.waiting { border-color: var(--mk-primary); }
    .mk-step.failed, .mk-step.uncertain { border-color: var(--mk-danger); }
    .mk-evidence { font-size: 13px; color: var(--mk-muted); margin: 8px 0 0; padding-left: 16px; }
    .mk-command { font-family: monospace; font-size: 12px; width: 100%; }
    .mk-hidden { display: none; }
</style>
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-primary">
            <div class="panel-heading">Install Mikrodius on {$mk_device.name|escape}</div>
            <div class="panel-body">
                {if !$mk_configured}
                    <div class="alert alert-warning">Save a Mikrodius API key in Settings first.</div>
                {/if}
                <p class="mk-muted">Check the router, choose which services use Mikrodius, then review and install.</p>
                <details class="mk-disclosure"><summary>Connection and installation details</summary>
                    <p>Management address <code>{$mk_device.ip_address|escape}</code>. PHPNuxBill uses its saved router login. Credentials stay on this server.</p>
                    <p class="mk-muted">Installation adds the Mikrodius tunnel, RADIUS and CoA settings. It enables RADIUS for the PPP and hotspot services you choose. Existing portals, pools and local users are kept. Test a subscriber login after installation.</p>
                </details>
                <div id="mk-alert"></div>
                <div id="mk-app"><p class="text-muted">Loading…</p></div>
            </div>
        </div>
        <div class="panel panel-default">
            <div class="panel-heading">Full install (fallback)</div>
            <div class="panel-body">
                <p>For routers PHPNuxBill cannot manage, paste the one-line install command into the router's terminal. <strong>It is not
                    the selective install above:</strong> it turns on PPP RADIUS and RADIUS on the default hotspot profile and on every
                    profile a hotspot server uses. Creating a command stops any earlier one; NAS credentials do not change.</p>
                <label class="checkbox-inline"><input type="checkbox" id="mk-full-approve"> I approve the full install's PPP and hotspot changes</label>
                <p style="margin-top:8px"><button type="button" class="btn btn-default btn-sm" id="mk-full-create">Create install command</button></p>
                <div id="mk-full-result" class="mk-hidden">
                    <textarea class="form-control mk-command" rows="3" readonly id="mk-full-command"></textarea>
                    <p class="help-block">Expires <span id="mk-full-expires"></span>. <button type="button" class="btn btn-link btn-xs" id="mk-full-copy">Copy</button></p>
                </div>
            </div>
        </div>
    </div>
</div>
<script type="application/json" id="mk-state">{$mk_state_json nofilter}</script>
<script>
    var mkEndpoint = {$mk_endpoint_json nofilter};
    var mkToken = {$mk_csrf_json nofilter};
</script>
{literal}
<script>
(function () {
    var state = JSON.parse(document.getElementById('mk-state').textContent);
    var app = document.getElementById('mk-app');
    var alertBox = document.getElementById('mk-alert');
    var inspection = null, preview = null, choice = null, polling = false, pollTimer = null, pollGeneration = 0;
    var STEPS = {inspect: 'Check the router again', nas: 'Create or link the NAS', preflight: 'Fetch NAS settings and check ownership',
        certificate: 'Trust the Mikrodius CA (SSTP)', tunnel: 'Configure the tunnel', reachability: 'Wait for the tunnel',
        radius: 'RADIUS entry, CoA rule and incoming CoA', services: 'Turn on RADIUS for PPP / hotspot', verify: 'Read back and check NAS status'};

    function el(tag, attrs) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'text') { node.textContent = attrs[k]; }
            else if (k === 'on') { Object.keys(attrs.on).forEach(function (e) { node.addEventListener(e, attrs.on[e]); }); }
            else if (attrs[k] !== null && attrs[k] !== false) { node.setAttribute(k, attrs[k] === true ? '' : attrs[k]); }
        });
        for (var i = 2; i < arguments.length; i++) {
            var c = arguments[i];
            if (c === null || c === undefined || c === false) { continue; }
            (Array.isArray(c) ? c : [c]).forEach(function (x) { node.appendChild(typeof x === 'string' ? document.createTextNode(x) : x); });
        }
        return node;
    }
    function call(action, params) {
        var body = new FormData();
        body.append('action', action);
        body.append('mikrodius_csrf', mkToken);
        Object.keys(params || {}).forEach(function (k) { body.append(k, typeof params[k] === 'string' ? params[k] : JSON.stringify(params[k])); });
        return fetch(mkEndpoint, {method: 'POST', body: body, credentials: 'same-origin', headers: {'X-Mikrodius-Csrf': mkToken}})
            .then(function (r) { return r.json().catch(function () { return {error: {code: 'http_' + r.status, message: 'Unexpected response.'}}; }); })
            .then(function (data) { if (data.error) { throw data.error; } return data; });
    }
    function showError(e) {
        alertBox.textContent = '';
        if (!e) { return; }
        alertBox.appendChild(el('div', {'class': 'alert alert-danger'}, el('strong', {text: e.message || 'Request failed.'}),
            e.detail ? [el('br'), e.detail] : null,
            el('br'), el('small', {text: 'Code: ' + e.code + (e.request_id ? ' · Request ID: ' + e.request_id : '')})));
    }
    function busy(button, promise) {
        button.disabled = true; showError(null);
        return promise.catch(showError).then(function () { button.disabled = false; });
    }
    function describe(value) {
        if (value === null || value === undefined) { return '—'; }
        if (typeof value !== 'object') { return String(value); }
        if (Array.isArray(value)) { return value.map(describe).join(', '); }
        return Object.keys(value).map(function (k) { return k + ': ' + describe(value[k]); }).join('; ');
    }
    function badge(stateName) {
        var cls = {succeeded: 'success', skipped: 'success', running: 'info', waiting: 'info', pending: 'default', failed: 'danger', uncertain: 'danger',
            needs_reconciliation: 'danger', cancelled: 'default'}[stateName] || 'default';
        return el('span', {'class': 'label label-' + cls, text: stateName.replace('_', ' ')});
    }

    // ------------------------------------------------------------ operation
    function renderOperation(op) {
        var box = el('div');
        box.appendChild(el('h4', null, 'Installation #' + op.id + ' ', badge(op.state)));
        box.appendChild(el('p', {'class': 'text-muted', text: 'NAS ' + (op.nas_id || op.nas.name + ' (to be created, ' + op.nas.connection + ')') +
            ' · PPP: ' + (op.services.ppp ? 'yes' : 'no') + ' · hotspot profiles: ' + (op.services.hotspot_profiles.join(', ') || 'none')}));
        op.steps.forEach(function (s) {
            var item = el('div', {'class': 'mk-step ' + s.state}, el('strong', {text: STEPS[s.step] || s.step}), ' ', badge(s.state),
                s.attempts > 1 ? el('small', {'class': 'text-muted', text: ' attempt ' + s.attempts}) : null);
            if (s.evidence) {
                item.appendChild(el('details', {'class': 'mk-disclosure'}, el('summary', {text:'Details'}), el('ul', {'class': 'mk-evidence'}, Object.keys(s.evidence).map(function (k) { return el('li', {text: k + ': ' + describe(s.evidence[k])}); }))));
            }
            if (s.error_code) {
                item.appendChild(el('div', {'class': 'text-danger', style: 'margin-top:4px'}, el('strong', {text: s.error_code}),
                    s.error_detail ? ' — ' + s.error_detail : null, s.request_id ? ' (request ' + s.request_id + ')' : null));
            }
            box.appendChild(item);
        });
        var actions = el('p', {style: 'margin-top:10px'});
        if (op.state === 'failed') {
            actions.appendChild(el('button', {'class': 'btn btn-primary btn-sm', type: 'button', text: 'Retry failed step', on: {click: function (e) {
                busy(e.target, call('retry', {operation: String(op.id)}).then(function (d) { show(d.operation); }));
            }}}));
        }
        if (op.state === 'needs_reconciliation') { box.appendChild(renderReconcile(op)); }
        if (op.state !== 'succeeded' && op.state !== 'cancelled') {
            actions.appendChild(document.createTextNode(' '));
            actions.appendChild(el('button', {'class': 'btn btn-default btn-sm', type: 'button', text: 'Cancel installation', on: {click: function (e) {
                if (!confirm('Stop this installation? Changes already made stay on the router.')) { return; }
                busy(e.target, call('cancel', {operation: String(op.id)}).then(function (d) { show(d.operation); }));
            }}}));
        } else {
            actions.appendChild(el('button', {'class': 'btn btn-default btn-sm', type: 'button', text: 'New installation / change services', on: {click: function () { state.operation = op; renderWizard(); }}}));
        }
        if (op.state === 'succeeded') {
            box.appendChild(el('div', {'class': 'alert alert-success'}, 'The router reads back as configured. Log in with a test subscriber to confirm authentication.'));
        }
        box.appendChild(actions);
        return box;
    }
    function renderReconcile(op) {
        var box = el('div', {'class': 'alert alert-warning'}, el('p', {text: 'Mikrodius did not confirm whether the NAS was created. Pick the NAS it created, or create one again if none appears. Nothing is linked or created automatically.'}));
        var list = el('div', null, el('em', {text: 'Loading NAS…'}));
        box.appendChild(list);
        call('candidates', {operation: String(op.id)}).then(function (d) {
            list.textContent = '';
            if (!d.candidates.length) { list.appendChild(el('p', {text: 'No unlinked NAS in this workspace.'})); }
            d.candidates.forEach(function (c) {
                list.appendChild(el('p', null, el('button', {'class': 'btn btn-xs btn-primary', type: 'button', text: 'Link', on: {click: function (e) {
                    busy(e.target, call('reconcile', {operation: String(op.id), resolution: 'link', nas_id: c.id}).then(function (r) { show(r.operation); }));
                }}}), ' ' + c.id + ' — ' + c.name + ' (' + c.connection + ', ' + c.status + ', created ' + c.created_at + ')' + (c.same_name ? ' — same name' : '')));
            });
            var confirmBox = el('input', {type: 'checkbox'});
            list.appendChild(el('p', null, el('label', null, confirmBox, ' None of these is it. Create the NAS again (a duplicate may be billed).'), ' ',
                el('button', {'class': 'btn btn-xs btn-danger', type: 'button', text: 'Create again', on: {click: function (e) {
                    busy(e.target, call('reconcile', {operation: String(op.id), resolution: 'create_again', confirm: confirmBox.checked ? 'yes' : 'no'}).then(function (r) { show(r.operation); }));
                }}})));
        }).catch(showError);
        return box;
    }
    function render(op) {
        state.operation = op;
        app.textContent = '';
        app.appendChild(renderOperation(op));
    }
    function stopPolling() {
        if (pollTimer !== null) { clearTimeout(pollTimer); pollTimer = null; }
        pollGeneration++; polling = false;
    }
    function show(op) {
        stopPolling(); render(op);
        if (op.state === 'running') { poll(op.id); }
    }
    function schedulePoll(id, delay) {
        if (pollTimer !== null) { clearTimeout(pollTimer); }
        pollTimer = setTimeout(function () { pollTimer = null; poll(id); }, delay);
    }
    function poll(id) {
        if (polling || !state.operation || state.operation.id !== id || state.operation.state !== 'running') { return; }
        var generation = pollGeneration;
        polling = true;
        call('advance', {operation: String(id)}).then(function (d) {
            if (generation !== pollGeneration) { return; }
            polling = false; render(d.operation);
            if (d.operation.state === 'running') { schedulePoll(id, 2000); }
        }).catch(function (e) {
            if (generation !== pollGeneration) { return; }
            polling = false; showError(e); schedulePoll(id, 5000);
        });
    }
    window.addEventListener('pagehide', stopPolling);
    window.addEventListener('pageshow', function (e) {
        if (e.persisted && state.operation) { poll(state.operation.id); }
    });

    // ------------------------------------------------------------ wizard
    function renderWizard() {
        stopPolling();
        app.textContent = '';
        if (state.operation) {
            app.appendChild(el('p', {'class': 'text-muted'}, 'Last installation #' + state.operation.id + ': ', badge(state.operation.state), ' ',
                el('a', {href: '#', text: 'show', on: {click: function (e) { e.preventDefault(); show(state.operation); }}})));
        }
        var inspectButton = el('button', {'class': 'btn btn-primary', type: 'button', text: '1. Check the router', on: {click: function (e) {
            busy(e.target, call('inspect').then(function (d) { inspection = d.inspection; renderChoice(); }));
        }}});
        app.appendChild(el('p', null, inspectButton));
        app.appendChild(el('div', {id: 'mk-choice'}));
        app.appendChild(el('div', {id: 'mk-preview'}));
    }
    function renderInspection() {
        var i = inspection, owners = Object.keys(i.owners || {});
        var rows = [
            ['RouterOS', i.routeros.text], ['Login', (i.access.user || '?') + ' (' + (i.access.group || '?') + ')' + (i.access.missing.length ? ' — missing ' + i.access.missing.join(', ') : '')],
            ['Mikrodius resources', owners.length ? owners.map(function (o) { return (o || 'untagged') + ': ' + i.owners[o].length; }).join(', ') : 'none'],
            ['Tunnels', i.tunnels.map(function (t) { return t.name + ' (' + t.type + (t.running ? ', running' : '') + ')'; }).join(', ') || 'none'],
            ['RADIUS entries', i.radius.map(function (r) { return r.address + ' [' + r.service + ']' + (r.disabled ? ' disabled' : '') + (r.owner !== null ? ' (Mikrodius)' : ''); }).join(', ') || 'none'],
            ['PPP', 'RADIUS ' + (i.ppp.aaa['use-radius'] === 'true' ? 'on' : 'off') + '; servers: ' + (i.ppp.servers.map(function (s) { return s.kind + ' ' + s.name; }).join(', ') || 'none') + '; local secrets: ' + i.ppp.local_users],
            ['Hotspot', 'servers: ' + (i.hotspot.servers.map(function (s) { return s.name + ' → ' + s.profile; }).join(', ') || 'none') + '; local users: ' + i.hotspot.local_users],
            ['CoA (incoming)', i.radius_incoming.accept ? 'accepted on ' + i.radius_incoming.port : 'off']
        ];
        return el('table', {'class': 'table table-condensed table-bordered'}, el('tbody', null, rows.map(function (r) { return el('tr', null, el('th', {text: r[0], style: 'width:180px'}), el('td', {text: r[1]})); })));
    }
    function renderChoice() {
        var box = document.getElementById('mk-choice'); box.textContent = '';
        document.getElementById('mk-preview').textContent = '';
        box.appendChild(el('details', {'class':'mk-disclosure'}, el('summary', {text:'Router inspection · RouterOS ' + inspection.routeros.text}), renderInspection()));
        box.appendChild(el('h4', {text: '2. Choose'}));
        var form = el('div', {'class': 'form-horizontal'});
        var mapping = state.mapping || {};
        var workspace = el('select', {'class': 'form-control'});
        var nasMode = el('select', {'class': 'form-control'}, el('option', {value: 'create', text: 'Create a new NAS'}), el('option', {value: 'link', text: 'Link an existing NAS'}));
        var nasName = el('input', {'class': 'form-control', maxlength: '100', value: state.device.name});
        // RouterOS 7 connects over WireGuard only; RouterOS 6 over L2TP or SSTP.
        var connection = el('select', {'class': 'form-control'}, (inspection.routeros.major >= 7 ? ['wireguard'] : ['l2tp', 'sstp']).map(function (c) { return el('option', {value: c, text: c}); }));
        var linkNas = el('select', {'class': 'form-control'});
        function group(label, control, help) { return el('div', {'class': 'form-group'}, el('label', {'class': 'col-sm-3 control-label', text: label}), el('div', {'class': 'col-sm-9'}, control, help ? el('p', {'class': 'help-block', text: help}) : null)); }
        if (mapping.nas_id) {
            form.appendChild(group('NAS', el('p', {'class': 'form-control-static', text: mapping.nas_id + ' in ' + mapping.workspace_id + ' (kept; its credentials do not change)'})));
        } else {
            if (mapping.workspace_id) { workspace.appendChild(el('option', {value: mapping.workspace_id, text: mapping.workspace_id})); }
            else { call('workspaces').then(function (d) { d.workspaces.forEach(function (w) { workspace.appendChild(el('option', {value: w.id, text: w.name + ' (' + w.id + ')'})); }); workspace.dispatchEvent(new Event('change')); }).catch(showError); }
            form.appendChild(group('Workspace', workspace));
            form.appendChild(group('NAS', nasMode));
            var createBox = el('div', null, group('NAS name', nasName), group('Connection', connection, 'WireGuard needs RouterOS v7. L2TP and SSTP cost more per NAS.'));
            var linkBox = el('div', {'class': 'mk-hidden'}, group('Existing NAS', linkNas, 'Only NAS not linked to another PHPNuxBill router are listed.'));
            form.appendChild(createBox); form.appendChild(linkBox);
            nasMode.addEventListener('change', function () { createBox.className = nasMode.value === 'create' ? '' : 'mk-hidden'; linkBox.className = nasMode.value === 'link' ? '' : 'mk-hidden'; });
            workspace.addEventListener('change', function () {
                linkNas.textContent = '';
                if (!workspace.value) { return; }
                call('nas_list', {workspace_id: workspace.value}).then(function (d) { d.nas.forEach(function (n) { linkNas.appendChild(el('option', {value: n.id, text: n.name + ' (' + n.id + ', ' + n.connection + ', ' + n.status + ')'})); }); }).catch(showError);
            });
            if (mapping.workspace_id) { workspace.dispatchEvent(new Event('change')); }
        }
        var ppp = el('input', {type: 'checkbox'});
        form.appendChild(group('PPP', el('label', {'class': 'checkbox-inline'}, ppp, ' Use Mikrodius RADIUS for PPP (global: every PPP server on this router)')));
        var profiles = inspection.hotspot.profiles.map(function (p) {
            var box = el('input', {type: 'checkbox', value: p.name});
            return {box: box, node: el('div', {'class': 'checkbox'}, el('label', null, box, ' ' + p.name + (p.default ? ' (default)' : '') +
                ' — servers: ' + (p.servers.join(', ') || 'none') + (p.use_radius ? ' — already uses RADIUS' : '')))};
        });
        form.appendChild(group('Hotspot profiles', profiles.length ? el('div', null, profiles.map(function (p) { return p.node; })) : el('p', {'class': 'form-control-static', text: 'No hotspot profiles.'})));
        form.appendChild(el('div', {'class': 'form-group'}, el('div', {'class': 'col-sm-offset-3 col-sm-9'}, el('button', {'class': 'btn btn-primary', type: 'button', text: '3. Preview changes', on: {click: function (e) {
            choice = {ppp: ppp.checked, hotspot_profiles: profiles.filter(function (p) { return p.box.checked; }).map(function (p) { return p.box.value; }), acknowledge: {}};
            if (!mapping.nas_id) {
                choice.workspace_id = workspace.value;
                choice.nas = nasMode.value === 'create' ? {mode: 'create', name: nasName.value, connection: connection.value} : {mode: 'link', nas_id: linkNas.value};
            }
            busy(e.target, call('preview', {choice: choice}).then(function (d) { preview = d.preview; renderPreview(); }));
        }}}))));
        box.appendChild(form);
    }
    function renderPreview() {
        var box = document.getElementById('mk-preview'); box.textContent = '';
        box.appendChild(el('h4', {text: 'Changes'}));
        var changed = Object.keys(preview.settings).filter(function (k) { return preview.settings[k].changes; });
        box.appendChild(changed.length ? el('table', {'class': 'table table-condensed table-bordered'},
            el('thead', null, el('tr', null, el('th', {text: 'Setting'}), el('th', {text: 'Now'}), el('th', {text: 'After'}))),
            el('tbody', null, changed.map(function (k) { var s = preview.settings[k]; return el('tr', null, el('td', {text: s.label}), el('td', {text: describe(s.before)}), el('td', {text: describe(s.after)})); })))
            : el('p', {text: 'No setting outside the Mikrodius-owned resources changes.'}));
        box.appendChild(el('ul', null, preview.impacts.map(function (i) { return el('li', null, i.message, i.items.length ? el('br') : null, i.items.length ? el('small', {'class': 'text-muted', text: i.items.join(', ')}) : null); })));
        var acks = {}, hard = false;
        preview.blockers.forEach(function (b) {
            var row = el('div', {'class': 'alert ' + (b.acknowledge ? 'alert-warning' : 'alert-danger')}, el('strong', {text: b.message}),
                b.items.length ? el('div', null, el('small', {text: b.items.join(', ')})) : null);
            if (b.acknowledge) {
                var cb = el('input', {type: 'checkbox'}); acks[b.acknowledge] = acks[b.acknowledge] || []; acks[b.acknowledge].push(cb);
                if (choice.acknowledge[b.acknowledge]) { cb.checked = true; }
                row.appendChild(el('label', {style: 'font-weight:normal'}, cb, ' I understand and accept this'));
            } else { hard = true; }
            box.appendChild(row);
        });
        var start = el('button', {'class': 'btn btn-success', type: 'button', text: '4. Start installation', disabled: hard ? true : null, on: {click: function (e) {
            Object.keys(acks).forEach(function (k) { choice.acknowledge[k] = acks[k].every(function (cb) { return cb.checked; }); });
            busy(e.target, call('start', {choice: choice}).then(function (d) { show(d.operation); }));
        }}});
        box.appendChild(el('p', null, start, hard ? el('span', {'class': 'text-danger', text: ' Resolve the red items on the router first.'}) : null));
    }

    // ------------------------------------------------------------ full install
    document.getElementById('mk-full-create').addEventListener('click', function (e) {
        busy(e.target, call('install_link', {approve_full_install: document.getElementById('mk-full-approve').checked ? 'yes' : 'no'}).then(function (d) {
            document.getElementById('mk-full-command').value = d.install_link.command;
            document.getElementById('mk-full-expires').textContent = d.install_link.expires_at || '';
            document.getElementById('mk-full-result').className = '';
        }));
    });
    document.getElementById('mk-full-copy').addEventListener('click', function () {
        var t = document.getElementById('mk-full-command'); t.select();
        if (navigator.clipboard) { navigator.clipboard.writeText(t.value); } else { document.execCommand('copy'); }
    });

    if (state.operation && ['running', 'failed', 'needs_reconciliation'].indexOf(state.operation.state) >= 0) { show(state.operation); }
    else { renderWizard(); }
})();
</script>
{/literal}
</div>
{include file="sections/footer.tpl"}
