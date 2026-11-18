{include file="mikrodius_styles.tpl"}
<div class="mk-ui">
<header class="mk-topbar">
    <div class="mk-title">
        <a class="mk-brand" href="{$mk_base|escape}/overview"><span class="mk-brand-mark"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120" role="img" aria-label="Mikrodius">
  <rect width="120" height="120" rx="28" fill="#2563eb"/>
  <circle cx="60" cy="60" r="33" fill="none" stroke="#fff" stroke-width="10"/>
  <path d="M60 60 83 37" stroke="#fff" stroke-width="10" stroke-linecap="round"/>
  <circle cx="60" cy="60" r="10" fill="#fff"/>
</svg>
</span><span>Mikrodius</span></a>
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
