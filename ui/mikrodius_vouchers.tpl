{include file="sections/header.tpl"}
{include file="mikrodius_nav.tpl"}
<div class="panel panel-primary panel-hovered">
    <div class="panel-heading">Voucher mode per plan</div>
    <div class="panel-body">
        <p>PHPNuxBill still generates and sells the codes. Choose, <strong>before printing codes</strong>, how each plan's codes reach Mikrodius:</p>
        <ul>
            <li><strong>Direct login</strong> (default): the code itself becomes the PPPoE or hotspot username and password. Minute, hour and
                day plans start their validity at first login; month and fixed-date plans need an exact expiry per code below.</li>
            <li><strong>Customer credit</strong>: the code stays in PHPNuxBill until a customer redeems it into their own account; the
                customer's login then gets the recharge.</li>
        </ul>
        <p class="text-muted">Each code keeps the mode it had when cron first synchronized it; changing the mode affects only codes not yet
            synchronized. The plugin cannot stop stock PHPNuxBill from accepting a code the wrong way: a direct-login code redeemed into an
            account, or a credit code used to log in, may already be saved as a billing record in PHPNuxBill before the conflict is detected.
            Such codes show as failed below and are not granted in Mikrodius; billing is not undone.</p>
    </div>
    <div class="table-responsive">
        <table class="table table-bordered table-condensed">
            <thead><tr><th>Plan</th><th>Type</th><th>Validity</th><th>Codes</th><th>Synchronized as</th><th>Mode for new codes</th></tr></thead>
            <tbody>
            {foreach $mk_plans as $mk_plan}
                <tr>
                    <td>{$mk_plan.name_plan|escape}<br><small class="text-muted">{$mk_plan.routers|escape}</small></td>
                    <td>{$mk_plan.type|escape}</td>
                    <td>{$mk_plan.validity|escape} {$mk_plan.validity_unit|escape}</td>
                    <td>{$mk_plan.codes|escape}</td>
                    <td><small>{$mk_plan.direct_codes|escape} direct · {$mk_plan.credit_codes|escape} credit</small></td>
                    <td><form method="post" action="{$mk_base|escape}/vouchers" class="form-inline" style="margin:0">
                            <input type="hidden" name="mikrodius_csrf" value="{$mk_csrf|escape}">
                            <input type="hidden" name="action" value="mode">
                            <input type="hidden" name="plan_id" value="{$mk_plan.id|escape}">
                            <select name="mode" class="form-control input-sm">
                                <option value="direct"{if $mk_plan.mode != 'customer_credit'} selected{/if}>Direct login{if !$mk_plan.mode} (default){/if}</option>
                                <option value="customer_credit"{if $mk_plan.mode == 'customer_credit'} selected{/if}>Customer credit</option>
                            </select>
                            <button type="submit" class="btn btn-primary btn-xs">Save</button>
                        </form></td>
                </tr>
            {foreachelse}
                <tr><td colspan="6" class="text-muted">No MikrodiusRadius plans.</td></tr>
            {/foreach}
            </tbody>
        </table>
    </div>
</div>
<div class="panel panel-primary panel-hovered">
    <div class="panel-heading">Codes
        <div class="btn-group pull-right">
            <a class="btn btn-default btn-xs{if $mk_filter == 'all'} active{/if}" href="{$mk_filters.all|escape}">Latest</a>
            <a class="btn btn-default btn-xs{if $mk_filter == 'attention'} active{/if}" href="{$mk_filters.attention|escape}">Needs attention</a>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-bordered table-condensed">
            <thead><tr><th>#</th><th>Code</th><th>Plan</th><th>PHPNuxBill</th><th>Mode</th><th>Expiry</th><th>Sync</th></tr></thead>
            <tbody>
            {foreach $mk_vouchers as $mk_v}
                <tr{if $mk_v.needs_expiry || $mk_v.job_state == 'failed'} class="warning"{/if}>
                    <td>{$mk_v.id|escape}</td>
                    <td><code>{$mk_v.code|escape}</code></td>
                    <td>{$mk_v.name_plan|escape}</td>
                    <td>{if $mk_v.status == '0'}unused{else}used{if $mk_v.used_date} {$mk_v.used_date|escape}{/if}{/if}</td>
                    <td>{if $mk_v.mode}{if $mk_v.mode == 'direct'}direct login{else}customer credit{/if}{else}<span class="text-muted">{if $mk_v.plan_mode == 'direct'}direct login{else}customer credit{/if} (not yet synchronized)</span>{/if}</td>
                    <td>{if $mk_v.expires_at}{$mk_v.expires_at|escape}
                        {elseif $mk_v.validity_seconds}{$mk_v.validity_seconds|escape} s from first login{if $mk_v.first_login_at}<br><small>first login {$mk_v.first_login_at|escape} UTC</small>{/if}
                        {elseif $mk_v.exact_expiry}{$mk_v.exact_expiry|escape} <small class="text-muted">(pending sync)</small>
                        {elseif $mk_v.needs_expiry}
                            <form method="post" action="{$mk_base|escape}/vouchers" class="form-inline" style="margin:0">
                                <input type="hidden" name="mikrodius_csrf" value="{$mk_csrf|escape}">
                                <input type="hidden" name="action" value="expiry">
                                <input type="hidden" name="voucher_id" value="{$mk_v.id|escape}">
                                <input type="datetime-local" name="expires_at" class="form-control input-sm" required>
                                <small class="text-muted">{$mk_timezone|escape}</small>
                                <button type="submit" class="btn btn-warning btn-xs">Set exact expiry</button>
                            </form>
                        {else}<span class="text-muted">—</span>{/if}</td>
                    <td>{if $mk_v.job_state}<span class="label label-{if $mk_v.job_state == 'succeeded'}success{elseif $mk_v.job_state == 'failed'}danger{else}info{/if}">{$mk_v.job_state|escape}</span>{/if}
                        {if $mk_v.observed_at}<br><small class="text-muted">checked {$mk_v.observed_at|escape} UTC</small>{/if}
                        {if $mk_v.job_error}<br><small class="text-danger">{$mk_v.job_error|escape}{if $mk_v.job_request_id} (Request ID {$mk_v.job_request_id|escape}){/if}</small>{/if}</td>
                </tr>
            {foreachelse}
                <tr><td colspan="7" class="text-muted">No codes{if $mk_filter == 'attention'} need attention{/if}.</td></tr>
            {/foreach}
            </tbody>
        </table>
    </div>
</div>
</div>
{include file="sections/footer.tpl"}
