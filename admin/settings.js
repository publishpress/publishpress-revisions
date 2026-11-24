jQuery(document).ready(function ($) {

    // Tabs
    var $tabsWrapper = $('#publishpress-revisions-settings-tabs');
    $tabsWrapper.find('li').click(function (e) {
        e.preventDefault();
        $tabsWrapper.children('li').filter('.nav-tab-active').removeClass('nav-tab-active');
        var $link = $(this).children('a').first();
        var panel = $link.attr('href');
        var subtab = $link.data('rvy-subtab');
        var $active = subtab ? $tabsWrapper.children('li').not(this).has('a[href="' + panel + '"]').first() : $(this);
        $active.addClass('nav-tab-active');
        $tabsWrapper.find('a').removeAttr('aria-current');
        $active.children('a').first().attr('aria-current', 'true');

        $('table[id^="ppr-"]').hide();
        $(panel).show();
        $('input[name="ppr_tab"]').val(panel);
        if (subtab) {
            $(panel).find('.rvy-option-section-tabs a.' + subtab).trigger('click');
        }
    });

    // Measure the actual row so the shortcut adapts to translated labels and sidebar widths.
    var $promo = $tabsWrapper.children('.rvy-notifications-promo-tab');
    if ($promo.length) {
        var $firstTab = $tabsWrapper.children('li').first();
        var fitPromo = function () {
            $promo.removeClass('rvy-notifications-promo-wrapped');
            if ($promo[0].getBoundingClientRect().top > $firstTab[0].getBoundingClientRect().top + 1) {
                $promo.addClass('rvy-notifications-promo-wrapped');
            }
        };

        fitPromo();
        $(window).on('resize load', fitPromo);
        if (window.ResizeObserver) {
            new ResizeObserver(fitPromo).observe($tabsWrapper[0]);
        }
    }
});
