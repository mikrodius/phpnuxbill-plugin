{include file="sections/header.tpl"}
{include file="mikrodius_nav.tpl"}
{if !$mk_configured}<div class="alert alert-warning">Save a Mikrodius API key in <a href="{$mk_base|escape}/settings">Settings</a> first.</div>{/if}
<datalist id="mk-workspaces">{foreach $mk_workspaces as $mk_ws}<option value="{$mk_ws|escape}">{/foreach}</datalist>
<div class="panel panel-primary panel-hovered">
    <div class="panel-heading">Routers → workspace and NAS</div>
    <div class="panel-body">
        <p class="text-muted">A router's plans and customers sync to its workspace. Link it to an existing NAS here when you install the
            router with the NAS install command; <a href="{$mk_base|escape}/nas">NAS &amp; routers</a> creates and links one for you instead.
            A router's workspace cannot change once mapped.</p>
    </div>
    <div class="table-responsive">
        <table class="table table-bordered table-condensed">
            <thead><tr><th>Router</th><th>Address</th><th>Workspace</th><th>NAS ID</th><th></th></tr></thead>
            <tbody>
            {foreach $mk_devices as $mk_row}
                <tr>
                    <td>{$mk_row.name|escape}</td>
                    <td>{$mk_row.ip_address|escape}</td>
                    <td><input form="mk-device-{$mk_row.id|escape}" class="form-control input-sm" name="workspace_id" list="mk-workspaces"
                        value="{$mk_row.workspace_id|escape}" placeholder="ws_…" required{if $mk_row.workspace_id} readonly{/if}></td>
                    <td><input form="mk-device-{$mk_row.id|escape}" class="form-control input-sm" name="nas_id" value="{$mk_row.nas_id|escape}" placeholder="nas_… (optional)"></td>
                    <td><form id="mk-device-{$mk_row.id|escape}" method="post" action="{$mk_base|escape}/mappings" style="margin:0">
                            <input type="hidden" name="mikrodius_csrf" value="{$mk_csrf|escape}">
                            <input type="hidden" name="action" value="device">
                            <input type="hidden" name="device_id" value="{$mk_row.id|escape}">
                            <button type="submit" class="btn btn-primary btn-xs"{if !$mk_configured} disabled{/if}>Save</button>
                        </form></td>
                </tr>
            {foreachelse}
                <tr><td colspan="5" class="text-muted">No routers yet.</td></tr>
            {/foreach}
            </tbody>
        </table>
    </div>
</div>
<div class="panel panel-primary panel-hovered">
    <div class="panel-heading">PHPNuxBill plans → Mikrodius plans</div>
    <div class="panel-body">
        <p class="text-muted">Only plans whose device is <strong>MikrodiusRadius</strong> are listed. Leave the Mikrodius plan empty to
            let the plugin create and manage one from the PHPNuxBill plan. Linking an existing Mikrodius plan leaves it unmanaged unless
            you tick <em>managed</em>, in which case its speed and limits follow PHPNuxBill.</p>
    </div>
    <div class="table-responsive">
        <table class="table table-bordered table-condensed">
            <thead><tr><th>Plan</th><th>Type</th><th>Router</th><th>Workspace</th><th>Mikrodius plan</th><th>Managed</th><th>Last sync (UTC)</th><th></th></tr></thead>
            <tbody>
            {foreach $mk_plans as $mk_row}
                <tr>
                    <td>{$mk_row.name_plan|escape}{if !$mk_row.enabled} <span class="label label-default">disabled</span>{/if}</td>
                    <td>{$mk_row.type|escape}</td>
                    <td>{$mk_row.routers|escape}</td>
                    <td><input form="mk-plan-{$mk_row.id|escape}" class="form-control input-sm" name="workspace_id" list="mk-workspaces"
                        value="{$mk_row.workspace_id|escape}" placeholder="ws_…" required{if $mk_row.workspace_id} readonly{/if}></td>
                    <td><input form="mk-plan-{$mk_row.id|escape}" class="form-control input-sm" name="remote_id" value="{$mk_row.remote_id|escape}"
                        placeholder="created automatically"{if $mk_row.remote_id} readonly{/if}></td>
                    <td><input form="mk-plan-{$mk_row.id|escape}" type="checkbox" name="managed" value="yes"{if $mk_row.managed || !$mk_row.workspace_id} checked{/if}></td>
                    <td>{$mk_row.updated_at|default:'—'|escape}</td>
                    <td><form id="mk-plan-{$mk_row.id|escape}" method="post" action="{$mk_base|escape}/mappings" style="margin:0">
                            <input type="hidden" name="mikrodius_csrf" value="{$mk_csrf|escape}">
                            <input type="hidden" name="action" value="plan">
                            <input type="hidden" name="plan_id" value="{$mk_row.id|escape}">
                            <button type="submit" class="btn btn-primary btn-xs"{if !$mk_configured} disabled{/if}>Save</button>
                        </form></td>
                </tr>
            {foreachelse}
                <tr><td colspan="8" class="text-muted">No MikrodiusRadius plans. Create a plan and choose the MikrodiusRadius device.</td></tr>
            {/foreach}
            </tbody>
        </table>
    </div>
</div>
</div>
{include file="sections/footer.tpl"}
