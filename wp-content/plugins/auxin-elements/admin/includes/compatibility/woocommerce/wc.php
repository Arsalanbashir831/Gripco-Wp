<?php

//
// ─── DISABLE AUTOMATIC REQUIRED PAGE CREATION ───────────────────────────────────
//

add_filter( 'woocommerce_create_pages', 'auxin_disable_automatic_page_creation', 1, 1 );
function auxin_disable_automatic_page_creation( $pages ) {
    if ( ! empty( $_GET['action'] ) && $_GET['action'] == 'install_pages' ) {
        return $pages;
    }
    return [];
}

/**
 * Preload customizer controls script because of an issue with global color and typography controller
 *
 * @return void
 */
function auxin_preload_customizer_controls_script() {
    if (function_exists('WC')) {
        $handle = 'load-custom-controls';
        wp_register_script( $handle, false, array( 'auxin_plugins', 'auxin_script' ), AUXELS_VERSION, false );
        wp_enqueue_script( $handle );
    }
}
add_action('customize_controls_init', 'auxin_preload_customizer_controls_script');
?>