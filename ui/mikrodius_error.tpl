{include file="sections/header.tpl"}
{include file="mikrodius_nav.tpl"}
<div class="alert alert-danger">
    <strong>{$mk_error.message|escape}</strong>
    {if $mk_error.detail}<br>{$mk_error.detail|escape}{/if}
    <br><small>Code: {$mk_error.code|escape}{if $mk_error.request_id} · Request ID: {$mk_error.request_id|escape}{/if}</small>
</div>
</div>
{include file="sections/footer.tpl"}
