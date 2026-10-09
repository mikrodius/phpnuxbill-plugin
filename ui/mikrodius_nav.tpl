{include file="mikrodius_styles.tpl"}
<div class="mk-ui">
<header class="mk-topbar">
    <div class="mk-title">
        <a class="mk-brand" href="{$mk_base|escape}/overview"><svg class="mk-brand-mark" viewBox="0 0 120 120" aria-hidden="true" focusable="false"><g fill="none" stroke="currentColor" stroke-width="12" stroke-linecap="round"><circle cx="60" cy="60" r="44"/><path d="M60 60 91 29"/></g><circle cx="60" cy="60" r="12" fill="currentColor"/></svg><span>Mikrodius</span></a>
        {if $mk_page != 'overview' && isset($mk_tabs[$mk_page])}<span class="mk-crumb" aria-hidden="true">/</span><span class="mk-crumb-page">{$mk_tabs[$mk_page]|escape}</span>{/if}
    </div>
    <details class="mk-advanced" id="mk-tools">
        <summary>Tools</summary>
        <nav class="mk-advanced-links" aria-label="Mikrodius tools">
            {if $mk_page != 'overview'}<a href="{$mk_base|escape}/overview">← Back to connection</a>{/if}
            {foreach $mk_tabs as $mk_key => $mk_label}
                {if $mk_key != 'overview'}
                    <a href="{$mk_base|escape}/{$mk_key|escape}"{if $mk_page == $mk_key} aria-current="page"{/if}>{$mk_label|escape}</a>
                {/if}
            {/foreach}
            <span class="mk-version">Plugin v{$mk_version|escape}</span>
        </nav>
    </details>
</header>
{literal}
<script>
(function () {
    // The Tools menu closes on Escape or a click elsewhere, like other menus.
    var tools = document.getElementById('mk-tools');
    if (!tools) { return; }
    document.addEventListener('click', function (event) { if (tools.open && !tools.contains(event.target)) { tools.open = false; } });
    tools.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && tools.open) { tools.open = false; tools.querySelector('summary').focus(); }
    });
})();
</script>
{/literal}
