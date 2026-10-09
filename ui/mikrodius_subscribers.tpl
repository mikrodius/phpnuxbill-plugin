{include file="sections/header.tpl"}
{include file="mikrodius_nav.tpl"}
<div class="panel panel-primary panel-hovered">
    <div class="panel-heading">Subscribers
        <form method="get" action="" class="pull-right form-inline" style="margin:0">
            <input type="hidden" name="_route" value="plugin/mikrodius/subscribers">
            <input type="text" class="form-control input-sm" name="q" value="{$mk_search|escape}" placeholder="Username">
            <button type="submit" class="btn btn-default btn-xs">Search</button>
        </form>
    </div>
    <div class="panel-body">
        <p class="text-muted">Each PHPNuxBill customer plan or direct-login voucher maps to one Mikrodius subscriber. Access and expiry come
            from Mikrodius, as of the time shown; <em>Refresh</em> reads them again. Billing stays in PHPNuxBill. Times are UTC.</p>
    </div>
    <div class="table-responsive">
        <table class="table table-bordered table-condensed">
            <thead><tr><th>Username</th><th>Source</th><th>Plan</th><th>Mikrodius</th><th>Access</th><th>Expires</th><th>Login type</th><th>Last sync</th><th></th></tr></thead>
            <tbody>
            {foreach $mk_rows as $mk_row}
                <tr>
                    <td>{$mk_row.username|escape}</td>
                    <td>{if $mk_row.customer_id}<a href="{$mk_row.customer_url|escape}">Customer {$mk_row.customer_id|escape}</a>{else}{$mk_row.kind|escape}{/if}</td>
                    <td>{$mk_row.name_plan|default:'—'|escape}</td>
                    <td>{if $mk_row.subscriber_id}<code>{$mk_row.subscriber_id|escape}</code><br><small class="text-muted">{$mk_row.workspace_id|escape}</small>{else}<span class="label label-warning">not created yet</span>{/if}</td>
                    <td>{if $mk_row.status}
                            <span class="label label-{if $mk_row.status == 'active' && $mk_row.state == 'allowed'}success{else}danger{/if}">{$mk_row.status|escape} · {$mk_row.state|default:'?'|escape}</span>
                            {if $mk_row.online}<span class="label label-info">online</span>{/if}
                            <br><small class="text-muted">as of {$mk_row.observed_at|escape}</small>
                        {else}<span class="text-muted">—</span>{/if}</td>
                    <td>{if $mk_row.expires_at}{$mk_row.expires_at|escape}{elseif $mk_row.status}<span class="text-muted">never / first use</span>{else}—{/if}
                        {if $mk_row.first_login_at}<br><small class="text-muted">first login {$mk_row.first_login_at|escape}</small>{/if}</td>
                    <td>{$mk_row.service_type|default:'from plan'|escape}</td>
                    <td>{if $mk_row.last_job}
                            <span class="label label-{if $mk_row.last_job.state == 'succeeded'}success{elseif $mk_row.last_job.state == 'failed'}danger{else}info{/if}">{$mk_row.last_job.kind|escape} {$mk_row.last_job.state|escape}</span>
                            <br><small class="text-muted">{$mk_row.last_job.finished_at|default:$mk_row.last_job.created_at|escape}</small>
                            {if $mk_row.last_job.error_code}<br><small class="text-danger">{$mk_row.last_job.message|escape}{if $mk_row.last_job.request_id} (Request ID {$mk_row.last_job.request_id|escape}){/if}</small>{/if}
                        {else}<span class="text-muted">—</span>{/if}</td>
                    <td style="white-space:nowrap">
                        <form method="post" action="{$mk_base|escape}/subscribers" style="margin:0 0 4px">
                            <input type="hidden" name="mikrodius_csrf" value="{$mk_csrf|escape}">
                            <input type="hidden" name="action" value="refresh">
                            <input type="hidden" name="source" value="{$mk_row.source_key|escape}">
                            <button type="submit" class="btn btn-default btn-xs"{if !$mk_row.subscriber_id} disabled{/if}>Refresh</button>
                        </form>
                        {if $mk_row.kind == 'customer'}
                        <form method="post" action="{$mk_base|escape}/subscribers" class="form-inline" style="margin:0">
                            <input type="hidden" name="mikrodius_csrf" value="{$mk_csrf|escape}">
                            <input type="hidden" name="action" value="service_type">
                            <input type="hidden" name="source" value="{$mk_row.source_key|escape}">
                            <select name="service_type" class="form-control input-sm">
                                <option value="pppoe">pppoe</option><option value="hotspot">hotspot</option><option value="any">any</option>
                            </select>
                            <label class="small"><input type="checkbox" name="confirm" value="yes" required> change login type</label>
                            <button type="submit" class="btn btn-warning btn-xs">Apply</button>
                        </form>
                        {/if}
                    </td>
                </tr>
            {foreachelse}
                <tr><td colspan="9" class="text-muted">No subscribers synchronized yet.</td></tr>
            {/foreach}
            </tbody>
        </table>
    </div>
</div>
</div>
{include file="sections/footer.tpl"}
