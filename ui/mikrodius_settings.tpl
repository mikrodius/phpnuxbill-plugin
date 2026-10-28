{include file="sections/header.tpl"}
{include file="mikrodius_nav.tpl"}
<div class="mk-page-heading"><div><p class="mk-eyebrow">Advanced</p><h2>Connection settings</h2><p class="mk-muted">Manage the account connection used by this billing system.</p></div><a class="btn btn-default" href="{$mk_base|escape}/overview">Back to workspace</a></div>
{if $mk_device_problem}
<div class="alert alert-danger" role="alert"><strong>The MikrodiusRadius device could not be installed.</strong>
    Make <code>system/devices/</code> writable and reload this page, or copy
    <code>system/plugin/mikrodius/device/MikrodiusRadius.php</code> into <code>system/devices/</code>.</div>
{/if}
<div class="row">
    <div class="col-md-7">
        <div class="panel panel-primary panel-hovered">
            <div class="panel-heading">Connection</div>
            <div class="panel-body">
                <form method="post" action="{$mk_base|escape}/settings" autocomplete="off">
                    <input type="hidden" name="mikrodius_csrf" value="{$mk_csrf|escape}">
                    <input type="hidden" name="action" value="save">
                    <div class="form-group">
                        <label for="mk-key">API key</label>
                        <input type="password" class="form-control" id="mk-key" name="api_key" autocomplete="new-password"
                            placeholder="{if $mk_settings.api_key_hint}Saved: {$mk_settings.api_key_hint|escape} (leave empty to keep){else}mk_live_…{/if}"
                            {if !$mk_settings.configured}required{/if}>
                        <p class="help-block">A <strong>read_write</strong> key scoped to the workspaces this PHPNuxBill uses. The saved key is
                            encrypted and never shown again. <a href="{$mk_key_url|escape}" target="_blank" rel="noopener">Create a key</a></p>
                    </div>
                    <details class="mk-disclosure"><summary>API address and billing timezone</summary>
                    <div class="form-group">
                        <label for="mk-url">API address</label>
                        <input type="url" class="form-control" id="mk-url" name="api_url" value="{$mk_settings.api_url|escape}" required>
                    </div>
                    <div class="form-group">
                        <label for="mk-tz">Billing timezone</label>
                        <input type="text" class="form-control" id="mk-tz" name="timezone" value="{$mk_timezone|escape}" required>
                        <p class="help-block">The timezone PHPNuxBill computes expiry dates in, for example Asia/Jakarta.</p>
                    </div>
                    </details>
                    <button type="submit" class="btn btn-primary">Save and check</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="panel panel-default">
            <div class="panel-heading">Status</div>
            <table class="table table-condensed">
                <tr><th>Connection</th><td>{if $mk_settings.configured}<span class="label label-success">configured</span>{else}<span class="label label-warning">not configured</span>{/if}</td></tr>
                <tr><th>API key</th><td>{$mk_settings.api_key_hint|default:'—'|escape}</td></tr>
                <tr><th>Plugin version</th><td>{$mk_version|escape}</td></tr>
            </table>
            <div class="panel-body">
                <form method="post" action="{$mk_base|escape}/settings">
                    <input type="hidden" name="mikrodius_csrf" value="{$mk_csrf|escape}">
                    <input type="hidden" name="action" value="test">
                    <button type="submit" class="btn btn-default"{if !$mk_settings.configured} disabled{/if}>Test connection</button>
                </form>
                <details class="mk-disclosure"><summary>Installation details</summary>
                    <p class="help-block">Installation ID <code>{$mk_settings.installation_id|escape}</code></p>
                    <p class="help-block">Keep this ID and <code>system/plugin/mikrodius/private/key.php</code> when moving or upgrading.</p>
                </details>
            </div>
        </div>
        {if $mk_workspaces !== null}
            <div class="panel panel-success">
                <div class="panel-heading">Workspaces this key reaches</div>
                <table class="table table-condensed">
                    {foreach $mk_workspaces as $mk_ws}
                        <tr><td><code>{$mk_ws.id|escape}</code></td><td>{$mk_ws.name|escape}</td></tr>
                    {foreachelse}
                        <tr><td class="text-muted">None. Scope the key to at least one workspace.</td></tr>
                    {/foreach}
                </table>
                <div class="panel-body"><small class="text-muted">Reading workspaces cannot prove the key may write. A read-only key fails later with an access error.</small></div>
            </div>
        {/if}
    </div>
</div>
<div class="panel panel-default"><div class="panel-heading">Voucher modes</div><div class="panel-body">
{foreach $mk_voucher_plans as $mk_plan}<form method="post" action="{$mk_base|escape}/settings" class="form-inline">
<input type="hidden" name="mikrodius_csrf" value="{$mk_csrf|escape}"><input type="hidden" name="action" value="voucher_mode"><input type="hidden" name="plan_id" value="{$mk_plan.id|escape}">
<label>{$mk_plan.name_plan|escape} <select name="mode" class="form-control"><option value="direct"{if $mk_plan.mode === 'direct'} selected{/if}>Direct login</option><option value="customer_credit"{if $mk_plan.mode === 'customer_credit'} selected{/if}>Customer credit</option></select></label> <button type="submit" class="btn btn-default">Save mode</button>
</form>{foreachelse}<p>No MikrodiusRadius plans.</p>{/foreach}
</div></div>
<div class="panel panel-default"><div class="panel-heading">Calendar voucher expiry</div><div class="panel-body">
{foreach $mk_expiry_vouchers as $mk_voucher}<form method="post" action="{$mk_base|escape}/settings" class="form-inline">
<input type="hidden" name="mikrodius_csrf" value="{$mk_csrf|escape}"><input type="hidden" name="action" value="voucher_expiry"><input type="hidden" name="voucher_id" value="{$mk_voucher.id|escape}">
<label>{$mk_voucher.code|escape} <input type="datetime-local" name="expires_at" class="form-control" required></label> <span>{$mk_timezone|escape}</span> <button type="submit" class="btn btn-default">Set expiry</button>
</form>{foreachelse}<p>No unused unsynchronized calendar vouchers need an exact expiry.</p>{/foreach}
</div></div>
<div class="panel panel-default"><div class="panel-heading">Customer login type</div><div class="panel-body">
{foreach $mk_service_overrides as $mk_override}<form method="post" action="{$mk_base|escape}/settings" class="form-inline">
<input type="hidden" name="mikrodius_csrf" value="{$mk_csrf|escape}"><input type="hidden" name="action" value="service_type"><input type="hidden" name="source" value="{$mk_override.source_key|escape}">
<label>{$mk_override.username|escape} <select name="service_type" class="form-control"><option value="hotspot"{if $mk_override.service_type === 'hotspot'} selected{/if}>Hotspot</option><option value="pppoe"{if $mk_override.service_type === 'pppoe'} selected{/if}>PPPoE</option><option value="any"{if $mk_override.service_type === 'any'} selected{/if}>Both services</option></select></label> <label><input type="checkbox" name="confirm" value="yes" required> Apply to live sessions</label> <button type="submit" class="btn btn-default">Apply</button>
</form>{foreachelse}<p>No mapped customers.</p>{/foreach}
</div></div>
</div>
{include file="sections/footer.tpl"}
