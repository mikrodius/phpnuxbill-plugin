{include file="sections/header.tpl"}
{include file="mikrodius_nav.tpl"}
<div class="panel panel-default"><div class="panel-heading">Workspace Statistics</div><div class="panel-body">
<p>Read-only cloud subscribers and accounting usage. Install the compiled interface for period controls and charts.</p>
{if $mk_statistics.workspace}<h3>{$mk_statistics.workspace.name|escape}</h3>{/if}
{if $mk_statistics.error}<p role="alert">{$mk_statistics.error.message|escape}</p>{/if}
<table class="table table-condensed"><thead><tr><th>Username</th><th>Connection</th><th>Access</th></tr></thead><tbody>
{foreach $mk_statistics.rows as $mk_row}<tr><td>{$mk_row.username|escape}</td><td>{if $mk_row.online === true}Online{elseif $mk_row.online === false}Offline{else}Unavailable{/if}</td><td>{$mk_row.state|default:'Unavailable'|escape}</td></tr>
{foreachelse}<tr><td colspan="3">No subscribers available for this workspace.</td></tr>{/foreach}
</tbody></table>
<p><a href="{$mk_base|escape}/overview">Choose your workspace</a></p>
</div></div>
</div>
{include file="sections/footer.tpl"}
