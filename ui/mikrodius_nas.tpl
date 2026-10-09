{include file="sections/header.tpl"}
{include file="mikrodius_nav.tpl"}
<div class="mk-page-heading"><div><p class="mk-eyebrow">Router installations</p><h2>NAS &amp; routers</h2><p class="mk-muted">Manage the MikroTik devices linked to your workspaces.</p></div>
    <form method="post" action="{$mk_base|escape}/nas"><input type="hidden" name="mikrodius_csrf" value="{$mk_csrf|escape}"><input type="hidden" name="action" value="refresh"><button class="btn btn-default" type="submit"{if !$mk_configured} disabled{/if}>Refresh status</button></form>
</div>
{if !$mk_configured}<div class="alert alert-warning">Connect Mikrodius on the <a href="{$mk_base|escape}/overview">workspace page</a> to get started.</div>{/if}
<section class="mk-card">
    <div class="mk-section-header"><div><h3>Installation checklist</h3><p>Each PHPNuxBill router uses one NAS. Review services before installing.</p></div><form method="post" action="{$mk_base|escape}/overview"><input type="hidden" name="mikrodius_csrf" value="{$mk_csrf|escape}"><input type="hidden" name="action" value="prepare_all"><button class="btn btn-default" type="submit">Prepare new routers</button></form></div>
    <div class="table-responsive"><table class="table">
        <thead><tr><th scope="col">Router</th><th scope="col">NAS status</th><th scope="col">Installation</th><th scope="col">Action</th></tr></thead>
        <tbody>{foreach $mk_devices as $mk_row}<tr>
            <td><strong>{$mk_row.name|escape}</strong><span class="mk-row-detail">{$mk_row.ip_address|escape}</span>{if !$mk_row.enabled}<span class="mk-pill">Disabled</span>{/if}
                <details class="mk-disclosure"><summary>NAS details</summary><span class="mk-row-detail">Workspace: {$mk_row.workspace_id|default:'Not linked'|escape}</span><span class="mk-row-detail">NAS: {$mk_row.nas_id|default:'Not prepared'|escape}</span>{if $mk_row.nas_last_seen_at}<span class="mk-row-detail">Last seen: {$mk_row.nas_last_seen_at|escape}</span>{/if}</details></td>
            <td>{if $mk_row.nas_status == 'online'}<span class="mk-pill good">Connected</span>{elseif $mk_row.nas_status == 'offline'}<span class="mk-pill pending">Offline</span>{elseif $mk_row.nas_id}<span class="mk-pill pending">Ready to install</span>{else}<span class="mk-pill">Not prepared</span>{/if}</td>
            <td>{if $mk_row.operation}<span class="mk-pill {if $mk_row.operation.state == 'succeeded'}good{elseif $mk_row.operation.state == 'failed' || $mk_row.operation.state == 'needs_reconciliation'}bad{else}pending{/if}">{if $mk_row.operation.state == 'succeeded'}Installed{elseif $mk_row.operation.state == 'running'}Installing{elseif $mk_row.operation.state == 'needs_reconciliation'}Needs review{else}{$mk_row.operation.state|capitalize|escape}{/if}</span><span class="mk-row-detail">{$mk_row.operation.updated_at|escape}</span>{else}<span class="mk-muted">Not installed</span>{/if}</td>
            <td>{if $mk_row.enabled}{if $mk_row.nas_id || $mk_row.operation}<a class="btn {if !$mk_row.operation}btn-primary{else}btn-default{/if}" href="{$mk_base|escape}/provision/{$mk_row.id|escape}">{if $mk_row.operation && ($mk_row.operation.state == 'running' || $mk_row.operation.state == 'failed' || $mk_row.operation.state == 'needs_reconciliation')}Resume{elseif $mk_row.operation && $mk_row.operation.state == 'succeeded'}Check / reinstall{else}Install{/if}</a>{else}<a class="btn btn-default" href="{$mk_base|escape}/overview">Prepare NAS</a>{/if}{/if}</td>
        </tr>{foreachelse}<tr><td colspan="4"><div class="mk-empty"><h3>No routers yet</h3><p class="mk-muted">Add your MikroTik under Network › Routers, then prepare its NAS from the workspace page.</p></div></td></tr>{/foreach}</tbody>
    </table></div>
</section>
</div>
{include file="sections/footer.tpl"}
