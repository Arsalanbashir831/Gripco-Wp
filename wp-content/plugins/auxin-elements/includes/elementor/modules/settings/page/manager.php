<?php
namespace Auxin\Plugin\CoreElements\Elementor\Settings\Page;

use Auxin\Plugin\CoreElements\Elementor\Settings\Base\Manager as baseManager;


class Manager extends baseManager
{
    /**
     * Register Document Controls
     *
     * Add New Controls to Elementor Page Options
     * @param $document
     */
    public function register_controls ( $document ) {
        // TODO Add controls here
    }

    protected function save_settings( array $settings, $document, $data = null ){
        // TODO save the control values as page meta fields by looping through $settings array
        // $document->update_main_meta( $meta_key, $value );
    }
}
