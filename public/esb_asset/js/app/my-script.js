! function($) {
    "use strict";

    // select box with icon
    const loading_button = jQuery(".loading-button");

    loading_button.click(function() {

        const e = jQuery(this);
        const txt = e.data("clicked");
        console.log(txt);
        e.find('.loading-icon').css('display', "inline-block");
        e.find('.text').text(txt);

    });

    // menu active
    const activeMenu = () => {
        const lastPath = window.location.href.split("/").pop();
        jQuery(".main-menu-content ul li").each(function() {
            const menu = jQuery(this);
            const mlink = menu.find('a').attr('href');
            if (mlink == lastPath) {
                jQuery('.main-menu-content ul li').removeClass('active');
                menu.addClass('active');
            }
        });
    }
    activeMenu();

    /*****************dashboard***********************/
}(window, document, jQuery);