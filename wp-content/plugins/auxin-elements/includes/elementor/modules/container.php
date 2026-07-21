<?php
namespace Auxin\Plugin\CoreElements\Elementor\Modules;


class Container {

    /**
     * Instance of this class.
     *
     * @var      object
     */
    protected static $instance = null;


    public function __construct(){
        // Modify render
        add_action( 'elementor/frontend/container/before_render', array( $this, 'modify_render' ) );
    }

    /**
     * Return an instance of this class.
     *
     * @return    object    A single instance of this class.
     */
    public static function get_instance() {
        // If the single instance hasn't been set, set it now.
        if ( null == self::$instance ) {
            self::$instance = new self;
        }

        return self::$instance;
    }


    /**
     * Modify the render of column element
     *
     * @param  Elementor\Includes\Elements\Container $container Instance of column element
     *
     * @return void
     */
    public function modify_render( $container ){
        // Add parallax initializer to all upper containers elements
        if ( ! $container->get_data( 'isInner' ) ) {
            $container->add_render_attribute( '_wrapper', 'class', 'aux-parallax-section' );
        }
        
    }

}
