<?php
/**
 * Elementor compatibility
 *
 * 
 * @package    Auxin
 * @author     averta (c) 2014-2025
 * @link       http://averta.net
*/

// no direct access allowed
if ( ! defined('ABSPATH') )  exit;

/*----------------------------------------------------------------------------*/
/*  Elementor Hooks - Theme Locations
/*----------------------------------------------------------------------------*/

function auxin_register_elementor_locations( $elementor_theme_manager ) {

    $elementor_theme_manager->register_location(
        'header',
        array(
            'hook'         => 'auxin_after_inner_body_open',
            'remove_hooks' => array( 'auxin_the_main_header_section', 'auxin_the_top_header_section' )
        )
    );

    $elementor_theme_manager->register_location(
        'auxin_page_title',
        array(
            'hook'            => 'auxin_after_inner_body_open',
            'label'           => sprintf( __( 'Page Title (%s)', 'phlox-pro' ), THEME_NAME_I18N ),
            'multiple'        => false,
            'edit_in_content' => true,
            'remove_hooks'    => array( 'auxin_the_main_title_section', 'auxin_the_header_slider_section', 'auxin_the_archive_slider_section' )
        )
    );

    $elementor_theme_manager->register_location(
        'auxin_sidebar_primary',
        array(
            'label'           => __( 'Sidebar Primary (Phlox Pro)', 'phlox-pro' ),
            'multiple'        => false,
            'edit_in_content' => true
        )
    );

    $elementor_theme_manager->register_location(
        'auxin_subfooter',
        array(
            'label'           => sprintf( __( 'Sub Footer (%s)', 'phlox-pro' ), THEME_NAME_I18N ),
            'multiple'        => false,
            'edit_in_content' => false,
            'remove_hooks'    => array( 'auxin_the_site_subfooter' )
        )
    );

    $elementor_theme_manager->register_location(
        'footer',
        array(
            'hook'         => 'auxin_before_the_footer',
            'multiple'        => false,
            'edit_in_content' => false,
            'remove_hooks' => array( 'auxin_the_site_footer' )
        )
    );

    // $elementor_theme_manager->register_location( 'archive' );
    $elementor_theme_manager->register_location( 'single'  );

    // A hack to remove the theme sections hooks which do not have default WP hook priority
    if( $elementor_theme_manager->location_exits( 'header', true ) ){
        remove_action( 'auxin_after_inner_body_open' ,'auxin_the_main_header_section', 4 );
        remove_action( 'auxin_after_inner_body_open' ,'auxin_the_top_header_section' , 4 );
    }
}

add_action( 'elementor/theme/register_locations', 'auxin_register_elementor_locations' );

/*----------------------------------------------------------------------------*/
/*  Header Footer Elementor extenstion
/*----------------------------------------------------------------------------*/

/**
 * Adding support for "header footer elementor" plugin
 *
 * @return void
 */
function auxin_add_support_header_footer_elementor(){
    add_theme_support( 'header-footer-elementor' );
}
add_filter( 'after_setup_theme', 'auxin_add_support_header_footer_elementor' );


/**
 * Adding required hooks for "header footer elementor" plugin
 *
 * @return void
 */
function auxin_include_header_footer_elementor(){
    // check if the extension exists
    if( ! function_exists('hfe_header_enabled') ){
        return;
    }

    if ( hfe_header_enabled() ) {
        remove_action( 'auxin_after_inner_body_open' ,'auxin_the_main_header_section', 4 );
        remove_action( 'auxin_after_inner_body_open' ,'auxin_the_top_header_section' , 4 );
        add_action( 'auxin_after_inner_body_open' ,'hfe_render_header' , 4 );
    }

    if ( hfe_footer_enabled() ) {
        remove_action( 'auxin_before_the_footer' ,'auxin_the_site_footer' );
        add_action( 'auxin_before_the_footer' ,'hfe_render_footer' );
    }
}
add_action( 'auxin_loaded', 'auxin_include_header_footer_elementor' );

add_filter( 'auxin_content_main_class', 'auxin_editor_section_page_template', 1 , 1 );
function auxin_editor_section_page_template( $classes ) {
    global $post;
    if ( isset( $post->post_type ) && $post->post_type == 'elementor_library' && get_post_meta( $post->ID, '_elementor_template_type', true ) == 'section' ) {
        foreach ( $classes as $key => $class ) {
            if ( $class == 'aux-boxed-container' ) {
                unset( $classes[$key] );
                break;
            }
        }
        $classes[] = 'aux-full-container';
    }
    return $classes;
}

/**
 * Enqueue Elementor base styles
 *
 * @return void
 */
function auxin_elementor_frontend_before_enqueue_styles(){
    if ( auxin_is_true( auxin_get_option( 'site_override_elementor_max_width_layout', true ) ) ) {
        wp_enqueue_style('auxin-elementor-base' , THEME_URL . 'css/other/elementor.css' , array(), THEME_VERSION );
    }
}
add_action( 'wp_enqueue_scripts', 'auxin_elementor_frontend_before_enqueue_styles', 19);

/**
 * Add notice that container widget max-width can control through the customizer
 * 
 * @param mixed $element
 * @param mixed $args
 * 
 */
function auxin_add_container_size_notice( $element, $args) {
    $element->add_control(
        'aux_container_width_notice',
        [
            'type' => \Elementor\Controls_Manager::NOTICE,
            'notice_type' => 'info',
            'dismissible' => true,
            'heading' => esc_html__( 'Container Width', 'phlox-pro' ),
            'content' => esc_html__( "The container's max width is affected by the site’s max width in 'General > Layout' of the customizer. To override site max-width by elementor, switch off the 'Override elementor container size' option in the same tab in customizer.", 'phlox-pro' ),
        ]
    );
}
add_action( 'elementor/element/container/section_layout_container/after_section_start', 'auxin_add_container_size_notice', 10, 2 );

/**
 * Add some svg tags to elementor allowed tags
 *
 * @param array $tags
 * @return array
 */
function auxin_allow_elementor_svg_tags( $tags ) {

    return array_merge( $tags, [
        'feoffset', 
        'fecomposite', 
        'feflood'
    ] );
}
add_filter( 'elementor/files/svg/allowed_elements', 'auxin_allow_elementor_svg_tags');

/**
 * Add some svg attributes to elementor allowed attributes
 *
 * @param array $attributes
 * @return array
 */
function auxin_allow_elementor_svg_attributes( $attributes ) {
    return array_merge( $attributes, [
        'input',
        'result',
        'flood-color',
        'flood-opacity',
        'in',
        'in2',
        'operator',
        'mode'
    ]);
}
add_filter( 'elementor/files/svg/allowed_attributes', 'auxin_allow_elementor_svg_attributes');