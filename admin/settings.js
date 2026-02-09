jQuery(document).ready(function ($) {

    // Tabs
    var $tabsWrapper = $('#publishpress-revisions-settings-tabs');
    $tabsWrapper.find('li').click(function (e) {
        e.preventDefault();
        $tabsWrapper.children('li').filter('.nav-tab-active').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');

        var panel = $(this).find('a').first().attr('href');

        $('table[id^="ppr-"]').hide();
        $(panel).show();
        $('input[name="ppr_tab"]').val(panel);
    });
});
