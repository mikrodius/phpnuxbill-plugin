{include file="sections/header.tpl"}
{include file="mikrodius_nav.tpl"}
{function name=mk_feedback fb=null area='' id=''}{if $fb && $fb.area == $area}
<div class="mk-feedback {if $fb.type == 'error'}is-error{else}is-success{/if}"{if $id} id="{$id|escape}"{/if} role="{if $fb.type == 'error'}alert{else}status{/if}">
    <strong>{$fb.message|escape}</strong>
    {if $fb.detail}<span>{$fb.detail|escape}</span>{/if}
    {if $fb.next}<span>{$fb.next|escape}</span>{/if}
    {if $fb.request_id}<small>Request ID: {$fb.request_id|escape}</small>{/if}
</div>
{/if}{/function}
{if $mk_feedback && $mk_feedback.type == 'error' && $mk_feedback.area == 'workspace'}{assign var='mk_pick' value=$mk_feedback.values.workspace_id}{elseif $mk_workspace_id && !$mk_choose}{assign var='mk_pick' value=$mk_workspace_id}{else}{assign var='mk_pick' value=''}{/if}
{assign var='mk_key_failed' value=false}
{if $mk_connection_error || ($mk_feedback && $mk_feedback.area == 'key' && $mk_feedback.type == 'error')}{assign var='mk_key_failed' value=true}{/if}
{if $mk_device_problem}
<div class="mk-feedback is-error" role="alert">
    <strong>The MikrodiusRadius device could not be installed.</strong>
    <span>Make <code>system/devices/</code> writable and reload this page, or copy <code>system/plugin/mikrodius/device/MikrodiusRadius.php</code> into <code>system/devices/</code>.</span>
</div>
{/if}
<section class="mk-card mk-connect-card" aria-labelledby="mk-connection-title">
    <div class="mk-section-header">
        <h2 id="mk-connection-title">Connection</h2>
        <span class="mk-pill {if $mk_connection_error}pending{elseif $mk_settings.configured}good{/if}" role="status">{if $mk_connection_error}Needs attention{elseif $mk_settings.configured}Connected{else}Not connected{/if}</span>
    </div>
    <div class="mk-card-body">
        <form method="post" action="{$mk_base|escape}/overview" autocomplete="off" data-mk-once>
            <input type="hidden" name="mikrodius_csrf" value="{$mk_csrf|escape}">
            <input type="hidden" name="action" value="connect">
            <div class="mk-label-row">
                <label for="mk-connect-key">API key</label>
                {if $mk_settings.configured}<span class="mk-saved-key">Saved key <code>{$mk_settings.api_key_hint|escape}</code></span>{/if}
            </div>
            <div class="mk-inline-field">
                <input type="password" id="mk-connect-key" class="form-control" name="api_key"
                    placeholder="{if $mk_settings.configured}Leave blank to keep the saved key{else}mk_live_…{/if}"
                    autocomplete="new-password" spellcheck="false" autocapitalize="off"{if !$mk_settings.configured} required{/if}{if $mk_key_failed} aria-invalid="true"{/if}
                    aria-describedby="mk-key-help{if $mk_key_failed} mk-key-feedback{/if}">
                {if !$mk_settings.configured}
                    <button class="btn btn-primary" type="submit" data-mk-busy="Connecting…">Connect Mikrodius</button>
                {elseif $mk_connection_error}
                    <button class="btn btn-primary" type="submit" data-mk-busy="Checking…" data-mk-empty="Check again" data-mk-filled="Save and check">Save and check</button>
                {else}
                    <button class="btn btn-default" type="submit" data-mk-busy="Checking…" data-mk-empty="Check connection" data-mk-filled="Save new key">Save new key</button>
                {/if}
            </div>
            <p class="help-block" id="mk-key-help">{if $mk_settings.configured}Leave this blank to keep using the saved key. Paste a key only to replace it.{else}Copy it from your Mikrodius dashboard. It needs read and write access. No account or key yet? <a href="{$mk_key_url|escape}" target="_blank" rel="noopener">Create a key</a>.{/if}</p>
            {if $mk_feedback && $mk_feedback.area == 'key'}
                {mk_feedback fb=$mk_feedback area='key' id='mk-key-feedback'}
            {elseif $mk_connection_error}
                <div class="mk-feedback is-error" id="mk-key-feedback" role="alert">
                    <strong>{$mk_connection_error.message|escape}</strong>
                    {if $mk_connection_error.detail}<span>{$mk_connection_error.detail|escape}</span>{/if}
                    {if $mk_connection_error.next}<span>{$mk_connection_error.next|escape}</span>{/if}
                    {if $mk_connection_error.request_id}<small>Request ID: {$mk_connection_error.request_id|escape}</small>{/if}
                </div>
            {/if}
        </form>

        <div class="mk-divider"></div>

        {if $mk_settings.configured && !$mk_connection_error}
            {if $mk_workspaces}
            <form method="post" action="{$mk_base|escape}/overview" data-mk-once{if $mk_workspace_id} data-mk-current="{$mk_workspace_id|escape}" data-mk-confirm="Switch to the selected workspace? Routers already linked keep their NAS. New NAS are created in the selected workspace, at normal NAS pricing."{/if}>
                <input type="hidden" name="mikrodius_csrf" value="{$mk_csrf|escape}">
                <input type="hidden" name="action" value="select">
                <label for="mk-workspace-select">Workspace</label>
                <div class="mk-inline-field">
                    <select class="form-control" id="mk-workspace-select" name="workspace_id" required aria-describedby="mk-workspace-help">
                        <option value="" disabled{if !$mk_pick} selected{/if}>Choose your workspace</option>
                        {foreach $mk_workspaces as $mk_ws}<option value="{$mk_ws.id|escape}"{if $mk_pick == $mk_ws.id} selected{/if}>{$mk_ws.name|escape}</option>{/foreach}
                    </select>
                    {if $mk_workspace_id && !$mk_choose}
                        <button class="btn btn-default" type="submit" data-mk-busy="Saving…">Switch workspace</button>
                    {else}
                        <button class="btn btn-primary" type="submit" data-mk-busy="Saving…">Use this workspace</button>
                    {/if}
                </div>
                <p class="help-block" id="mk-workspace-help">{if $mk_workspace_id && !$mk_choose}Customers and plans from PHPNuxBill go to this workspace.{else}Your routers get a NAS in this workspace. Router settings stay unchanged until you choose Install.{/if}</p>
                {mk_feedback fb=$mk_feedback area='workspace'}
            </form>
            {else}
                <p class="mk-label-text">Workspace</p>
                <p class="mk-empty-line">You have no workspaces yet. Create your first one below.</p>
                {mk_feedback fb=$mk_feedback area='workspace'}
            {/if}
            <details class="mk-disclosure mk-create"{if !$mk_workspaces || ($mk_feedback && $mk_feedback.area == 'create')} open{/if}>
                <summary>Create a new workspace</summary>
                <form method="post" action="{$mk_base|escape}/overview" data-mk-once>
                    <input type="hidden" name="mikrodius_csrf" value="{$mk_csrf|escape}"><input type="hidden" name="action" value="create">
                    <label for="mk-workspace-name">Workspace name</label>
                    <div class="mk-inline-field">
                        <input class="form-control" id="mk-workspace-name" name="name" maxlength="100" placeholder="For example, your network name" required aria-describedby="mk-create-help"
                            value="{if $mk_feedback && $mk_feedback.area == 'create'}{$mk_feedback.values.name|escape}{/if}">
                        <button class="btn {if $mk_workspaces}btn-default{else}btn-primary{/if}" type="submit" data-mk-busy="Creating…">Create workspace</button>
                    </div>
                    <p class="help-block" id="mk-create-help">It is selected as soon as it is created. Normal workspace and NAS pricing applies.</p>
                    {mk_feedback fb=$mk_feedback area='create'}
                </form>
            </details>
        {else}
            <label for="mk-workspace-select">Workspace</label>
            <select class="form-control" id="mk-workspace-select" disabled aria-describedby="mk-workspace-wait"><option>{if $mk_connection_error}Waiting for a working API key{else}Connect your API key first{/if}</option></select>
            <p class="help-block" id="mk-workspace-wait">Your workspaces appear here once the API key works.</p>
        {/if}
    </div>
</section>
{if $mk_settings.configured && $mk_workspace_id && !$mk_connection_error}
<details class="mk-workspace-details" id="mk-workspace-details"{if $mk_feedback && ($mk_feedback.area == 'routers' || $mk_feedback.area == 'device')} open{/if}>
    <summary><span>Workspace details &amp; routers</span>{if $mk_router_todo} <span class="mk-pill pending">{$mk_router_todo|escape} not connected</span>{/if}</summary>
    <div class="mk-detail-content">
        {if $mk_error}<div class="mk-feedback is-error" role="alert"><strong>{$mk_error.message|escape}</strong>{if $mk_error.detail}<span>{$mk_error.detail|escape}</span>{/if}<span>Some workspace details could not load. Your connection is not affected; refresh to try again.</span></div>{/if}
        <div class="mk-stats-line">
            <span><strong>{$mk_stats.nas_online|default:'—'|escape}</strong> NAS online</span>
            <span><strong>{$mk_stats.subscribers_total|default:'—'|escape}</strong> subscribers</span>
            <span><strong>{$mk_stats.sessions_active|default:'—'|escape}</strong> active sessions</span>
            {if $mk_counts.failed}<a href="{$mk_base|escape}/jobs">{$mk_counts.failed|escape} sync issue{if $mk_counts.failed != 1}s{/if}</a>{else}<span>No sync issues</span>{/if}
        </div>

    <section class="mk-card" aria-labelledby="mk-routers-title">
        <div class="mk-section-header"><div><h3 id="mk-routers-title">Your routers</h3><p>Each router needs a NAS, then an install. Router settings change only when you install.</p></div>
            {if $mk_preparable}<form method="post" action="{$mk_base|escape}/overview" data-mk-once><input type="hidden" name="mikrodius_csrf" value="{$mk_csrf|escape}"><input type="hidden" name="action" value="prepare_all"><button class="btn btn-default" type="submit" data-mk-busy="Starting…">Prepare new routers</button></form>{/if}</div>
        <div id="mk-prepare-progress" class="mk-progress" role="status" aria-live="polite" hidden></div>
        {if $mk_feedback && $mk_feedback.area == 'routers'}<div class="mk-section-feedback">{mk_feedback fb=$mk_feedback area='routers'}</div>{/if}
        <div class="table-responsive"><table class="table">
            <thead><tr><th scope="col">Router</th><th scope="col">Connection</th><th scope="col">Status</th><th scope="col">Next step</th></tr></thead>
            <tbody>
            {foreach $mk_devices as $mk_row}
                <tr id="mk-router-{$mk_row.id|escape}">
                    <td><strong>{$mk_row.name|escape}</strong><span class="mk-row-detail">{$mk_row.ip_address|escape}</span></td>
                    <td>{if $mk_row.connection == 'wireguard'}WireGuard{elseif $mk_row.connection == 'sstp'}SSTP{elseif $mk_row.connection == 'l2tp'}L2TP{else}<span class="mk-muted">Automatic</span>{/if}
                        {if $mk_row.radius_plans}<span class="mk-row-detail">{$mk_row.radius_plans|escape} Mikrodius plans</span>{/if}</td>
                    <td data-preparation-status aria-live="polite">
                        {if !$mk_row.enabled}<span class="mk-pill">Disabled</span>
                        {elseif $mk_row.other_workspace}<span class="mk-pill">Other workspace</span>
                        {elseif $mk_row.operation && ($mk_row.operation.state == 'running' || $mk_row.operation.state == 'failed' || $mk_row.operation.state == 'needs_reconciliation')}<span class="mk-pill pending">Installation needs attention</span>
                        {elseif $mk_row.nas_status == 'online'}<span class="mk-pill good">Connected</span>
                        {elseif $mk_row.nas_status == 'offline'}<span class="mk-pill pending">Offline</span>
                        {elseif $mk_row.nas_id}<span class="mk-pill pending">Ready to install</span>
                        {elseif $mk_row.uncertain}<span class="mk-pill bad">Review NAS creation</span>
                        {elseif $mk_row.prepare_error}<span class="mk-pill pending">Needs a check</span><span class="mk-row-detail">{$mk_row.prepare_error|escape}</span>
                        {else}<span class="mk-pill">Not prepared</span>{/if}
                    </td>
                    <td><div class="mk-row-actions">
                        {if $mk_row.enabled && !$mk_row.other_workspace}
                            {if $mk_row.nas_id || $mk_row.operation}
                                <a class="btn {if !$mk_row.operation || $mk_row.operation.state == 'cancelled'}btn-primary{else}btn-default{/if}" href="{$mk_base|escape}/provision/{$mk_row.id|escape}">{if $mk_row.operation && ($mk_row.operation.state == 'running' || $mk_row.operation.state == 'failed' || $mk_row.operation.state == 'needs_reconciliation')}Resume installation{elseif $mk_row.nas_status == 'online' || ($mk_row.operation && $mk_row.operation.state == 'succeeded')}Check installation{else}Install{/if}</a>
                            {elseif $mk_row.can_prepare}
                                <button type="button" class="btn btn-primary mk-prepare-one" data-device="{$mk_row.id|escape}">Prepare NAS</button>
                            {/if}
                        {elseif !$mk_row.enabled}
                            <span class="mk-row-detail">Enable it in PHPNuxBill first.</span>
                        {/if}
                    </div>
                    {if $mk_feedback && $mk_feedback.area == 'device' && $mk_feedback.values.device_id == $mk_row.id}{mk_feedback fb=$mk_feedback area='device'}{/if}
                    {if $mk_row.enabled && !$mk_row.other_workspace && !$mk_row.nas_id && !$mk_row.operation}
                        <details class="mk-disclosure"{if $mk_row.uncertain || ($mk_feedback && $mk_feedback.area == 'device' && $mk_feedback.type == 'error' && $mk_feedback.values.device_id == $mk_row.id)} open{/if}><summary>{if $mk_row.uncertain}Link an existing NAS{else}Set up manually or link an existing NAS{/if}</summary>
                            {if $mk_unmapped}
                            <form method="post" action="{$mk_base|escape}/overview" data-mk-once>
                                <input type="hidden" name="mikrodius_csrf" value="{$mk_csrf|escape}"><input type="hidden" name="action" value="link"><input type="hidden" name="device_id" value="{$mk_row.id|escape}">
                                <label for="mk-link-{$mk_row.id|escape}">Existing NAS</label>
                                <div class="mk-inline-field"><select class="form-control" id="mk-link-{$mk_row.id|escape}" name="nas_id" required>
                                    {foreach $mk_unmapped as $mk_nas}<option value="{$mk_nas.id|escape}">{$mk_nas.name|escape} · {$mk_nas.connection|escape} · {$mk_nas.id|escape}</option>{/foreach}
                                </select><button class="btn btn-default" type="submit" data-mk-busy="Linking…">Link NAS</button></div>
                            </form>
                            {/if}
                            {if !$mk_row.uncertain}
                            <form method="post" action="{$mk_base|escape}/overview" data-mk-once>
                                <input type="hidden" name="mikrodius_csrf" value="{$mk_csrf|escape}"><input type="hidden" name="action" value="prepare"><input type="hidden" name="device_id" value="{$mk_row.id|escape}"><input type="hidden" name="workspace_id" value="{$mk_workspace_id|escape}">
                                <label for="mk-manual-{$mk_row.id|escape}">RouterOS version</label>
                                <div class="mk-inline-field"><select class="form-control" id="mk-manual-{$mk_row.id|escape}" name="connection" required aria-describedby="mk-manual-help-{$mk_row.id|escape}"><option value="wireguard">RouterOS 7 · WireGuard</option><option value="sstp">RouterOS 6 · SSTP</option><option value="l2tp">RouterOS 6 · L2TP</option></select>
                                <button class="btn btn-default" type="submit" data-mk-busy="Preparing…">Prepare for manual install</button></div>
                                <p class="help-block" id="mk-manual-help-{$mk_row.id|escape}">Use this when PHPNuxBill cannot reach the router. You then copy the install command from its installation page.</p>
                            </form>
                            {else}
                                <form method="post" action="{$mk_base|escape}/overview" data-mk-once>
                                    <input type="hidden" name="mikrodius_csrf" value="{$mk_csrf|escape}"><input type="hidden" name="action" value="prepare_again"><input type="hidden" name="device_id" value="{$mk_row.id|escape}"><input type="hidden" name="workspace_id" value="{$mk_workspace_id|escape}">
                                    <p class="help-block">Check the workspace’s NAS list first. A duplicate NAS may cost extra.</p>
                                    <label class="mk-check"><input type="checkbox" name="confirm" value="yes" required> I checked the NAS list. None belongs to this router.</label>
                                    <button class="btn btn-default" type="submit" data-mk-busy="Creating…">Create NAS again</button>
                                </form>
                            {/if}
                        </details>
                    {/if}
                    </td>
                </tr>
            {foreachelse}<tr><td colspan="4"><div class="mk-empty"><h3>No routers yet</h3><p class="mk-muted">Add your MikroTik in PHPNuxBill, then prepare its NAS here.</p><a class="btn btn-primary" href="{$mk_add_router_url|escape}">Add a router</a></div></td></tr>{/foreach}
            </tbody>
        </table></div>
    </section>
    </div>
</details>
{/if}
<script type="application/json" id="mk-preparation-data">{$mk_prepare_json nofilter}</script>
<script>var mkWorkspaceEndpoint = {$mk_endpoint_json nofilter}; var mkWorkspaceToken = {$mk_csrf_json nofilter}; var mkWorkspaceId = {$mk_workspace_json nofilter};</script>
{literal}
<script>
(function () {
    var progress = document.getElementById('mk-prepare-progress'), running = false;
    function prepare(id) {
        var row = document.getElementById('mk-router-' + id), status = row.querySelector('[data-preparation-status]');
        status.textContent = 'Checking router and preparing NAS…';
        var body = new FormData(); body.append('action', 'prepare'); body.append('device_id', String(id)); body.append('workspace_id', mkWorkspaceId); body.append('mikrodius_csrf', mkWorkspaceToken);
        return fetch(mkWorkspaceEndpoint, {method:'POST', body:body, credentials:'same-origin', headers:{'X-Mikrodius-Csrf':mkWorkspaceToken}})
            .then(function (r) { return r.json(); }).then(function (data) {
                if (data.error) { status.textContent = data.error.message; return false; }
                status.textContent = 'NAS ready. Router settings are unchanged.'; return true;
            }).catch(function () { status.textContent = 'Could not confirm the result. Refresh to review before retrying.'; return false; });
    }
    function run(ids) {
        if (running || !ids.length) { return; } running = true;
        var details = document.getElementById('mk-workspace-details');
        if (details) { details.open = true; }
        progress.hidden = false; progress.textContent = 'Preparing ' + ids.length + ' router(s)… Keep this page open.';
        document.querySelectorAll('.mk-prepare-one').forEach(function (b) { b.disabled = true; });
        var chain = Promise.resolve(); ids.forEach(function (id, index) { chain = chain.then(function () { progress.textContent = 'Preparing router ' + (index + 1) + ' of ' + ids.length + '… Keep this page open.'; return prepare(id); }); });
        chain.then(function () { window.location.assign(mkWorkspaceEndpoint); });
    }
    document.querySelectorAll('.mk-prepare-one').forEach(function (b) { b.addEventListener('click', function () { run([b.getAttribute('data-device')]); }); });
    run(JSON.parse(document.getElementById('mk-preparation-data').textContent));
})();
</script>
{/literal}
{literal}
<script>
(function () {
    // The key button says what a submit will do: check the saved key, or save the typed one.
    var key = document.getElementById('mk-connect-key'), keyButton = key && key.form.querySelector('[type=submit]');
    function keyLabel() {
        if (keyButton && keyButton.hasAttribute('data-mk-filled') && !keyButton.disabled) {
            keyButton.textContent = key.value.trim() ? keyButton.getAttribute('data-mk-filled') : keyButton.getAttribute('data-mk-empty');
        }
    }
    if (key) { key.addEventListener('input', keyLabel); keyLabel(); }
    // One submission per form; switching away from a saved workspace asks first.
    document.querySelectorAll('form[data-mk-once]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form.getAttribute('aria-busy') === 'true') { event.preventDefault(); return; }
            var current = form.getAttribute('data-mk-current'), select = form.querySelector('select[name=workspace_id]');
            if (current && select && select.value && select.value !== current && !window.confirm(form.getAttribute('data-mk-confirm'))) { event.preventDefault(); return; }
            var button = form.querySelector('[type=submit]');
            form.setAttribute('aria-busy', 'true');
            if (button) { button.setAttribute('data-mk-label', button.textContent); button.textContent = button.getAttribute('data-mk-busy') || 'Working…'; button.disabled = true; }
        });
    });
    // Coming back with the browser's Back button restores usable forms.
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) { return; }
        document.querySelectorAll('form[aria-busy="true"]').forEach(function (form) {
            var button = form.querySelector('[type=submit]');
            form.removeAttribute('aria-busy');
            if (button) { button.disabled = false; button.textContent = button.getAttribute('data-mk-label') || button.textContent; }
        });
        keyLabel();
    });
})();
</script>
{/literal}
</div>
{include file="sections/footer.tpl"}
