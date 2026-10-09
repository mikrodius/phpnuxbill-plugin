{include file="sections/header.tpl"}
{include file="mikrodius_nav.tpl"}
<div class="row">
    <div class="col-md-6">
        <div class="panel panel-primary panel-hovered">
            <div class="panel-heading">Endpoint</div>
            <div class="panel-body">
                <p>In the Mikrodius dashboard, add a webhook endpoint with this URL and copy its signing secret here:</p>
                <pre style="white-space:pre-wrap;word-break:break-all">{$mk_endpoint|escape}</pre>
                <p class="text-muted">Webhooks only speed up status updates; cron reads the same state from the API when they are missing.
                    They never create payments, renew access or change routers. The plugin's API key does not need webhook permissions.</p>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="panel panel-primary panel-hovered">
            <div class="panel-heading">Signing secret</div>
            <div class="panel-body">
                <p>{if $mk_secret_set}<span class="label label-success">saved</span> The secret is encrypted and never shown again.
                    {else}<span class="label label-warning">not set</span> Deliveries are refused until a secret is saved.{/if}
                    {if $mk_previous_until}<br><small class="text-muted">The previous secret is accepted until {$mk_previous_until|escape}.</small>{/if}</p>
                <form method="post" action="{$mk_base|escape}/webhooks" autocomplete="off">
                    <input type="hidden" name="mikrodius_csrf" value="{$mk_csrf|escape}">
                    <input type="hidden" name="action" value="secret">
                    <div class="form-group">
                        <label for="mk-secret">{if $mk_secret_set}New secret{else}Secret{/if}</label>
                        <input type="password" class="form-control" id="mk-secret" name="secret" placeholder="whsec_…" autocomplete="new-password" required>
                    </div>
                    {if $mk_secret_set}
                        <div class="form-group">
                            <label for="mk-keep">Keep accepting the current secret for</label>
                            <select class="form-control" id="mk-keep" name="keep_previous_hours">
                                <option value="0">no time (replace now)</option><option value="1">1 hour</option>
                                <option value="6">6 hours</option><option value="24" selected>24 hours</option>
                            </select>
                            <p class="help-block">While you rotate the secret in Mikrodius, deliveries signed with either secret are accepted.</p>
                        </div>
                    {/if}
                    <button type="submit" class="btn btn-primary">Save secret</button>
                </form>
            </div>
        </div>
    </div>
</div>
<div class="panel panel-primary panel-hovered">
    <div class="panel-heading">Deliveries
        <span class="text-muted small">{$mk_counts.pending|escape} pending · {$mk_counts.failed|escape} failed · {$mk_counts.processed|escape} processed</span>
        <form method="post" action="{$mk_base|escape}/webhooks" class="pull-right" style="margin:0">
            <input type="hidden" name="mikrodius_csrf" value="{$mk_csrf|escape}">
            <input type="hidden" name="action" value="process">
            <button type="submit" class="btn btn-default btn-xs"{if !$mk_counts.pending} disabled{/if}>Process pending now</button>
        </form>
    </div>
    <div class="panel-body"><p class="text-muted">Accepted deliveries are stored, then processed in order by cron. Times are UTC.</p></div>
    <div class="table-responsive">
        <table class="table table-bordered table-condensed">
            <thead><tr><th>Event</th><th>Type</th><th>Workspace</th><th>Happened</th><th>Received</th><th>State</th><th></th></tr></thead>
            <tbody>
            {foreach $mk_events as $mk_e}
                <tr{if $mk_e.state == 'failed'} class="danger"{/if}>
                    <td><code>{$mk_e.id|escape}</code></td>
                    <td>{$mk_e.event_type|escape}</td>
                    <td>{$mk_e.workspace_id|escape}</td>
                    <td><small>{$mk_e.event_at|escape}</small></td>
                    <td><small>{$mk_e.received_at|escape}</small></td>
                    <td><span class="label label-{if $mk_e.state == 'processed'}success{elseif $mk_e.state == 'failed'}danger{else}info{/if}">{$mk_e.state|escape}</span>
                        {if $mk_e.error_code}<br><small class="text-danger">{$mk_e.message|escape}</small><br><small class="text-muted">{$mk_e.error_code|escape}</small>{/if}</td>
                    <td>{if $mk_e.state == 'failed'}
                            <form method="post" action="{$mk_base|escape}/webhooks" style="margin:0">
                                <input type="hidden" name="mikrodius_csrf" value="{$mk_csrf|escape}">
                                <input type="hidden" name="action" value="retry">
                                <input type="hidden" name="event" value="{$mk_e.id|escape}">
                                <button type="submit" class="btn btn-warning btn-xs">Retry</button>
                            </form>
                        {/if}</td>
                </tr>
            {foreachelse}
                <tr><td colspan="7" class="text-muted">No deliveries received yet.</td></tr>
            {/foreach}
            </tbody>
        </table>
    </div>
</div>
</div>
{include file="sections/footer.tpl"}
