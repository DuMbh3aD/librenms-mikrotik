<a href="#" class="dropdown-toggle">
    <i class="fa fa-sitemap fa-fw fa-lg"></i> MikroTik
</a>
<ul class="dropdown-menu">
    <li>
        <a href="{{ url('plugin/MikroTik') }}">
            <i class="fa fa-cog fa-fw"></i> Settings
        </a>
    </li>
    <li>
        <a href="{{ url('plugin/MikroTik/device-models') }}">
            <i class="fa fa-microchip fa-fw"></i> Device Models
        </a>
    </li>
    <li>
        <a href="{{ route('mikrotik.connections') }}">
            <i class="fa fa-code-fork fa-fw"></i> Connections
        </a>
    </li>
    <li>
        <a href="{{ route('mikrotik.topology') }}">
            <i class="fa fa-sitemap fa-fw"></i> Network Map
        </a>
    </li>
</ul>

<script>
$(function () {
    var $link = $('#navHeaderCollapse a').filter(function () {
        return $(this).text().trim() === 'MikroTik';
    }).first();

    if (!$link.length) return;

    var $source = $link.parent('li');
    var $submenu = $source.children('ul.dropdown-menu').first();

    if (!$submenu.length) return;

    var $topMenu = $('<li class="dropdown"></li>');
    var $toggle = $('<a href="#" class="dropdown-toggle" data-toggle="dropdown" data-hover="dropdown"></a>');

    $toggle.html('<i class="fa fa-sitemap fa-fw fa-lg fa-nav-icons"></i> MikroTik <b class="caret"></b>');

    $topMenu.append($toggle);
    $topMenu.append($submenu.detach());

    $('#navHeaderCollapse > ul.navbar-nav').first().append($topMenu);

    $source.remove();
});
</script>
