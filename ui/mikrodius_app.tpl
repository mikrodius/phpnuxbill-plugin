{include file="sections/header.tpl"}
{literal}
<style>
body:has(#mikrodius-react-host) .content-header { display: none; }
body:has(#mikrodius-react-host) .content-wrapper { min-height: 0 !important; }
body:has(#mikrodius-react-host) .content { padding: 8px 12px !important; }
</style>
{/literal}
<div id="mikrodius-react-host"><p>Mikrodius is loading…</p></div>
<script type="application/json" id="mikrodius-react-bootstrap">{$mk_app_boot_json nofilter}</script>
<script type="module" src="{$mk_app_asset|escape}"></script>
<noscript>Enable JavaScript to use the Mikrodius app.</noscript>
{include file="sections/footer.tpl"}
