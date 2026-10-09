<?php
namespace Mikrodius;

/**
 * Adds a Mikrodius button to PHPNuxBill's router list and edit pages through their
 * `view_list_routers` / `view_router_edit` hooks and the theme's `xfooter` slot. PHPNuxBill
 * runs only the first callback registered for a hook, so this may not appear when another
 * plugin claims it; the Mikrodius NAS page always offers the same action.
 */
final class Shortcut
{
    public static function routers(): void
    {
        global $ui, $routes, $d;
        $admin = \Admin::_info();
        $admin = $admin ? (is_array($admin) ? $admin : $admin->as_array()) : [];
        if (!isset($ui) || !in_array($admin['user_type'] ?? '', Ui::ROLES, true)) { return; }
        $edit = ($routes[1] ?? '') === 'edit';
        $ids = [];
        if ($edit && $d) { $ids[] = (int) $d['id']; }
        elseif (!$edit && is_iterable($d)) { foreach ($d as $row) { $ids[] = (int) $row['id']; } }
        if (!$ids) { return; }
        $data = Ui::embed(['base' => getUrl('plugin/mikrodius/provision/'), 'edit' => $edit, 'ids' => $ids]);
        $script = <<<HTML
<script>(function () {
  var d = {$data};
  function button(id) {
    var a = document.createElement('a');
    a.className = 'btn btn-success btn-xs mikrodius-shortcut';
    a.href = d.base + id;
    a.textContent = 'Mikrodius';
    a.title = 'Install or check Mikrodius RADIUS on this router';
    a.style.marginLeft = '4px';
    return a;
  }
  function run() {
    if (d.edit) {
      var heading = document.querySelector('.panel-heading');
      if (heading) { heading.appendChild(button(d.ids[0])); }
      return;
    }
    d.ids.forEach(function (id) {
      document.querySelectorAll('a[href*="routers/edit/' + id + '"]').forEach(function (link) {
        if (link.href.match(new RegExp('routers/edit/' + id + '($|[^0-9])'))) { link.parentNode.insertBefore(button(id), link.nextSibling); }
      });
    });
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', run); } else { run(); }
})();</script>
HTML;
        $ui->assign('xfooter', (string) ($ui->getTemplateVars('xfooter') ?? '') . $script);
    }
}
