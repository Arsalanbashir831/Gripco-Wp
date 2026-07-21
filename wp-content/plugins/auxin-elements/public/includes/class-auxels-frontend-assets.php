<?php
/**
 * Load frontend scripts and styles
 *
 * 
 * @package    Auxin
 * @license    LICENSE.txt
 * @author     averta
 * @link       http://phlox.pro/
 * @copyright  (c) 2010-2026 averta
 */

/**
* Constructor
*/
class AUXELS_Frontend_Assets {


	/**
	 * Construct
	 */
	public function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'load_assets'  ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'load_elementor_header_footer_assets'  ), 20 );
	}

    /**
     * Styles for admin
     *
     * @return void
     */
    public function load_assets() {
        
        // fix compatibility issue with "Elementor Addon Elements" plugin 
        wp_deregister_script( 'wts-isotope' );

        if( $google_map_api_key = auxin_get_option( 'auxin_google_map_api_key') ){
            wp_enqueue_script( 'mapapi', esc_url( set_url_scheme( 'http://maps.googleapis.com/maps/api/js?v=3&key='. $google_map_api_key ) ) , null, null, true );
        }

        //wp_enqueue_style( AUXELS_SLUG .'-main',   AUXELS_PUB_URL . '/assets/css/main.css',  array(), AUXELS_VERSION );
        wp_enqueue_script( AUXELS_SLUG .'-plugins', AUXELS_PUB_URL . '/assets/js/plugins.min.js', array('jquery'), AUXELS_VERSION, true );
        wp_enqueue_script( AUXELS_SLUG .'-scripts', AUXELS_PUB_URL . '/assets/js/scripts.js', array('jquery'), AUXELS_VERSION, true );
    }

    public function load_elementor_header_footer_assets() {
        if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
            // Enqueue header template styles in header
            if( $header_template_style = auxin_get_option( 'site_elementor_header_template' ) ){
                $css_file = new \Elementor\Core\Files\CSS\Post( $header_template_style );
                $css_file->enqueue();

                // Enqueue any additional JS/CSS required for the document
                $page_assets = get_post_meta( $header_template_style, \Elementor\Core\Base\Elements_Iteration_Actions\Assets::ASSETS_META_KEY, true );
                if ( ! empty( $page_assets ) ) {
                    \Elementor\Plugin::$instance->assets_loader->enable_assets( $page_assets );
                }
                
            }

            if( $footer_template_style = auxin_get_option( 'site_elementor_footer_template' ) ){
                $css_file = new \Elementor\Core\Files\CSS\Post( $footer_template_style );
                $css_file->enqueue();

                // Enqueue any additional JS/CSS required for the document
                $page_assets = get_post_meta( $footer_template_style, \Elementor\Core\Base\Elements_Iteration_Actions\Assets::ASSETS_META_KEY, true );
                if ( ! empty( $page_assets ) ) {
                    \Elementor\Plugin::$instance->assets_loader->enable_assets( $page_assets );
                }
            }
        }
    }

}
return new AUXELS_Frontend_Assets();





