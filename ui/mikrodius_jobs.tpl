{include file="sections/header.tpl"}
{include file="mikrodius_nav.tpl"}
{if $mk_counts.paid_pending > 0}
    <div class="alert alert-warning"><strong>{$mk_counts.paid_pending|escape} paid purchase(s) waiting for Mikrodius access.</strong>
        PHPNuxBill has saved these payments; only the RADIUS access is pending. Nobody needs to pay again: cron keeps retrying,
        and <em>Retry</em> below queues a failed one again.</div>
{/if}
<div class="panel panel-primary panel-hovered">
    <div class="panel-heading">Sync jobs
        <div class="btn-group pull-right">
            <a class="btn btn-default btn-xs{if $mk_filter == 'all'} active{/if}" href="{$mk_filters.all|escape}">All</a>
            <a class="btn btn-default btn-xs{if $mk_filter == 'open'} active{/if}" href="{$mk_filters.open|escape}">Open ({$mk_counts.pending|escape})</a>
            <a class="btn btn-default btn-xs{if $mk_filter == 'failed'} active{/if}" href="{$mk_filters.failed|escape}">Failed ({$mk_counts.failed|escape})</a>
        </div>
    </div>
    <div class="panel-body">
        <p class="text-muted">Each billing change in PHPNuxBill becomes a job that cron sends to Mikrodius in order, per customer or voucher.
            A failed job holds back later jobs for the same customer until it succeeds or is retried. <em>Retry</em> only repeats the
            synchronization; it never charges, renews or changes billing in PHPNuxBill. Checks marked <em>diagnostic</em> clear themselves
            on the next cron run once the cause is fixed. Times are UTC.</p>
    </div>
    <div class="table-responsive">
        <table class="table table-bordered table-condensed">
            <thead><tr><th>#</th><th>For</th><th>Kind</th><th>State</th><th>Problem</th><th>Attempts</th><th>Created</th><th>Next try / finished</th><th></th></tr></thead>
            <tbody>
            {foreach $mk_jobs as $mk_job}
                <tr{if $mk_job.state == 'failed'} class="danger"{elseif $mk_job.category == 'paid_pending'} class="warning"{/if}>
                    <td>{$mk_job.id|escape}</td>
                    <td>{if $mk_job.link}<a href="{$mk_job.link|escape}">{$mk_job.subject|escape}</a>{else}{$mk_job.subject|escape}{/if}
                        {if $mk_job.source_txn > 0}<br><small class="text-muted">transaction {$mk_job.source_txn|escape}</small>{/if}</td>
                    <td>{$mk_job.kind|escape}
                        {if $mk_job.category == 'paid_pending'}<br><span class="label label-warning">paid, provisioning pending</span>
                        {elseif $mk_job.category == 'billing'}<br><span class="label label-danger">billing record problem</span>{/if}</td>
                    <td><span class="label label-{if $mk_job.state == 'succeeded'}success{elseif $mk_job.state == 'failed'}danger{elseif $mk_job.state == 'superseded'}default{else}info{/if}">{$mk_job.state|escape}</span></td>
                    <td>{if $mk_job.error_code}{$mk_job.message|escape}<br><small class="text-muted">{$mk_job.error_code|escape}{if $mk_job.request_id} · Request ID {$mk_job.request_id|escape}{/if}</small>{/if}</td>
                    <td>{$mk_job.attempts|escape}</td>
                    <td><small>{$mk_job.created_at|escape}</small></td>
                    <td><small>{if $mk_job.finished_at}{$mk_job.finished_at|escape}{else}{$mk_job.available_at|escape}{/if}</small></td>
                    <td>{if $mk_job.retryable}
                            <form method="post" action="{$mk_base|escape}/jobs" style="margin:0">
                                <input type="hidden" name="mikrodius_csrf" value="{$mk_csrf|escape}">
                                <input type="hidden" name="action" value="retry">
                                <input type="hidden" name="job" value="{$mk_job.id|escape}">
                                <button type="submit" class="btn btn-warning btn-xs">Retry sync</button>
                            </form>
                        {/if}</td>
                </tr>
            {foreachelse}
                <tr><td colspan="9" class="text-muted">No jobs.</td></tr>
            {/foreach}
            </tbody>
        </table>
    </div>
</div>
</div>
{include file="sections/footer.tpl"}
