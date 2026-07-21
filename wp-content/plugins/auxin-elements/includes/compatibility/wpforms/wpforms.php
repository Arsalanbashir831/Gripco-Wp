<?php
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Core\Kits\Documents\Tabs\Global_Typography;
use Elementor\Group_Control_Border;

function auxin_extend_wpforms_elementor_widget($element, $args) {
    if ($element->get_name() !== 'wpforms') {
        return;
    }

    $element->start_controls_section(
            'aux_core_general_input_section',
            [
                'label'      => __('All Inputs', 'auxin-elements' ),
                'tab'       => Controls_Manager::TAB_STYLE,
            ]
        );

        $element->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name' => 'general_input_typography',
                'label' => __( 'Typography', 'auxin-elements' ),
                'global' => [
					'default' => Global_Typography::TYPOGRAPHY_PRIMARY,
				],
                'selector' => '{{WRAPPER}} input'
            ]
        );

        $element->add_control(
            'general_input_color',
            [
                'label' => __( 'Color', 'auxin-elements' ),
                'type' => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} input,{{WRAPPER}} textarea, {{WRAPPER}} select option, {{WRAPPER}} select' => 'color: {{VALUE}};'
                ]
            ]
        );

        $element->add_responsive_control(
            'general_input_width',
            [
                'label' => __( 'Width', 'auxin-elements' ),
                'size_units' => [ 'px','em', '%'],
                'type' => Controls_Manager::SLIDER,
                'range' => [
                    'px' => [
                        'min' => 0,
                        'max' => 1000,
                        'step' => 5,
                    ],
                    '%' => [
                        'min' => 0,
                        'max' => 100,
                    ],
                    'em' => [
                        'min' => 0,
                        'max' => 100,
                    ],
                ],
                'selectors' => [
                    '{{WRAPPER}} input' => 'width: {{SIZE}}{{UNIT}};'
                ]
            ]
        );

        $element->add_responsive_control(
            'general_input_max_width',
            [
                'label' => __( 'Max Width', 'auxin-elements' ),
                'size_units' => [ 'px','em', '%'],
                'type' => Controls_Manager::SLIDER,
                'range' => [
                    'px' => [
                        'min' => 0,
                        'max' => 1000,
                        'step' => 5
                    ],
                    '%' => [
                        'min' => 0,
                        'max' => 100
                    ],
                    'em' => [
                        'min' => 0,
                        'max' => 100
                    ]
                ],
                'selectors' => [
                    '{{WRAPPER}} input' => 'max-width: {{SIZE}}{{UNIT}};'
                ]
            ]
        );

        $element->add_responsive_control(
            'general_input_height',
            [
                'label' => __( 'Height', 'auxin-elements' ),
                'size_units' => [ 'px', 'em'],
                'type' => Controls_Manager::SLIDER,
                'range' => [
                    'px' => [
                        'min' => 0,
                        'max' => 1000,
                        'step' => 5
                    ],
                    '%' => [
                        'min' => 0,
                        'max' => 100
                    ],
                    'em' => [
                        'min' => 0,
                        'max' => 100
                    ]
                ],
                'selectors' => [
                    '{{WRAPPER}} input' => 'height: {{SIZE}}{{UNIT}};'
                ],
                'separator' => 'after'
            ]
        );


        $element->add_responsive_control(
            'general_input_border_radius',
            [
                'label' => __( 'Border Radius', 'auxin-elements' ),
                'type' => Controls_Manager::DIMENSIONS,
                'size_units' => [ 'px', '%' ],
                'selectors' => [
                    '{{WRAPPER}} input' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'
                ],
            ]
        );

        $element->add_responsive_control(
            'general_input_padding',
            [
                'label' => __( 'Padding', 'auxin-elements' ),
                'type' => Controls_Manager::DIMENSIONS,
                'size_units' => [ 'px', '%', 'em' ],
                'selectors' => [
                    '{{WRAPPER}} input' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'
                ],
                'separator' => 'after'
            ]
        );

        $element->add_responsive_control(
            'general_input_margin',
            [
                'label' => __( 'Margin', 'auxin-elements' ),
                'type' => Controls_Manager::DIMENSIONS,
                'size_units' => [ 'px', '%', 'em' ],
                'selectors' => [
                    '{{WRAPPER}} input' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'
                ],
                'separator' => 'after'
            ]
        );

        // Background and Box Shadow for input - START
        $element->start_controls_tabs( 'general_input_tabs' );

        $element->start_controls_tab(
            'general_input_tab_normal_state',
            [
                'label' => __( 'Normal', 'auxin-elements' ),
            ]
        );

        $element->add_group_control(
            Group_Control_Background::get_type(),
            [
                'name' => 'general_input_background',
                'selector' => '{{WRAPPER}} input',
                'types' => [ 'classic', 'gradient']
            ]
        );

        $element->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name' => 'general_input_box_shadow',
                'selector' => '{{WRAPPER}} input'
            ]
        );

        $element->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name' => 'general_input_border',
                'selector' => '{{WRAPPER}} input'
            ]
        );

        $element->end_controls_tab();

        $element->start_controls_tab(
            'general_input_tab_hover_state',
            [
                'label' => __( 'Hover', 'auxin-elements' ),
            ]
        );

        $element->add_group_control(
            Group_Control_Background::get_type(),
            [
                'name' => 'general_input_background_hover',
                'selector' => '{{WRAPPER}} input:hover',
                'types' => [ 'classic', 'gradient']
            ]
        );

        $element->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name' => 'general_input_box_shadow_hover',
                'selector' => '{{WRAPPER}} input:hover'
            ]
        );

        $element->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name' => 'general_input_border_hover',
                'selector' => '{{WRAPPER}} input:hover'
            ]
        );

        $element->add_control(
            'general_input_transition',
            [
                'label' => __( 'Transition Duration', 'auxin-elements' ),
                'type' => Controls_Manager::SLIDER,
                'default' => [
                    'size' => 0.3,
                ],
                'range' => [
                    'px' => [
                        'max' => 3,
                        'step' => 0.1,
                    ],
                ],
                'render_type' => 'ui',
                'selectors' => [
                    '{{WRAPPER}} input' => "transition:all ease-out {{SIZE}}s;"
                ]
            ]
        );

        $element->end_controls_tab();

        $element->start_controls_tab(
            'general_input_tab_focus_state',
            [
                'label' => __( 'Focus', 'auxin-elements' ),
            ]
        );

        $element->add_group_control(
            Group_Control_Background::get_type(),
            [
                'name' => 'general_input_background_focus',
                'selector' => '{{WRAPPER}} input:focus, {{WRAPPER}} input:focus:invalid',
                'types' => [ 'classic', 'gradient']
            ]
        );

        $element->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name' => 'general_input_box_shadow_focus',
                'selector' => '{{WRAPPER}} input:focus, {{WRAPPER}} input:focus:invalid'
            ]
        );

        $element->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name' => 'general_input_border_focus',
                'selector' => '{{WRAPPER}} input:focus, {{WRAPPER}} input:focus:invalid'
            ]
        );

        $element->end_controls_tab();

        $element->end_controls_tabs();
        // Background and Box Shadow for input - END

        $element->end_controls_section();

        /* -------------------------------------------------------------------------- */
        /* Placeholder Style
        /* -------------------------------------------------------------------------- */
        $element->start_controls_section(
            'aux_core_placeholder_section',
            [
                'label'    => __('Input Placeholder', 'auxin-elements' ),
                'tab'      => Controls_Manager::TAB_STYLE
            ]
        );

        $element->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name' => 'placeholder_typography',
                'label' => __( 'Typography', 'auxin-elements' ),
                'global' => [
					'default' => Global_Typography::TYPOGRAPHY_PRIMARY,
				],
                'selector' => '{{WRAPPER}} input::placeholder'
            ]
        );

        $element->add_control(
            'placeholder_color',
            [
                'label' => __( 'Color', 'auxin-elements' ),
                'type' => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} input::placeholder' => 'color: {{VALUE}};'
                ]
            ]
        );

        $element->end_controls_section();

        /* -------------------------------------------------------------------------- */
        /* Text Input Style
        /* -------------------------------------------------------------------------- */

        $element->start_controls_section(
            'aux_core_text_input_section',
            [
                'label'      => __('Text Inputs', 'auxin-elements' ),
                'tab'       => Controls_Manager::TAB_STYLE,
            ]
        );

        $element->add_control(
            'text_input_color',
            [
                'label' => __( 'Color', 'auxin-elements' ),
                'type' => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} input[type="text"]' => 'color: {{VALUE}};'
                ]
            ]
        );

        $element->add_responsive_control(
            'text_input_width',
            [
                'label' => __( 'Width', 'auxin-elements' ),
                'size_units' => [ 'px','em', '%'],
                'type' => Controls_Manager::SLIDER,
                'range' => [
                    'px' => [
                        'min' => 0,
                        'max' => 1000,
                        'step' => 5,
                    ],
                    '%' => [
                        'min' => 0,
                        'max' => 100,
                    ],
                    'em' => [
                        'min' => 0,
                        'max' => 100,
                    ],
                ],
                'selectors' => [
                    '{{WRAPPER}} input[type="text"]' => 'width: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $element->add_responsive_control(
            'text_input_max_width',
            [
                'label' => __( 'Max Width', 'auxin-elements' ),
                'size_units' => [ 'px','em', '%'],
                'type' => Controls_Manager::SLIDER,
                'range' => [
                    'px' => [
                        'min' => 0,
                        'max' => 1000,
                        'step' => 5
                    ],
                    '%' => [
                        'min' => 0,
                        'max' => 100
                    ],
                    'em' => [
                        'min' => 0,
                        'max' => 100
                    ]
                ],
                'selectors' => [
                    '{{WRAPPER}} input[type="text"]' => 'max-width: {{SIZE}}{{UNIT}};',
                ]
            ]
        );

        $element->add_responsive_control(
            'text_input_height',
            [
                'label' => __( 'Height', 'auxin-elements' ),
                'size_units' => [ 'px', 'em'],
                'type' => Controls_Manager::SLIDER,
                'range' => [
                    'px' => [
                        'min' => 0,
                        'max' => 1000,
                        'step' => 5
                    ],
                    '%' => [
                        'min' => 0,
                        'max' => 100
                    ],
                    'em' => [
                        'min' => 0,
                        'max' => 100
                    ]
                ],
                'selectors' => [
                    '{{WRAPPER}} input[type="text"]' => 'height: {{SIZE}}{{UNIT}};',
                ],
                'separator' => 'after'
            ]
        );


        $element->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name' => 'text_input_border',
                'selector' => '{{WRAPPER}} input[type="text"]'
            ]
        );

        $element->add_responsive_control(
            'text_input_border_radius',
            [
                'label' => __( 'Border Radius', 'auxin-elements' ),
                'type' => Controls_Manager::DIMENSIONS,
                'size_units' => [ 'px', '%' ],
                'selectors' => [
                    '{{WRAPPER}} input[type="text"]' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $element->add_responsive_control(
            'text_input_padding',
            [
                'label' => __( 'Padding', 'auxin-elements' ),
                'type' => Controls_Manager::DIMENSIONS,
                'size_units' => [ 'px', '%', 'em' ],
                'selectors' => [
                    '{{WRAPPER}} input[type="text"]' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
                'separator' => 'after'
            ]
        );

        // Background and Box Shadow for input - START
        $element->start_controls_tabs( 'text_input_tabs' );

        $element->start_controls_tab(
            'text_input_tab_normal_state',
            [
                'label' => __( 'Normal', 'auxin-elements' ),
            ]
        );

        $element->add_group_control(
            Group_Control_Background::get_type(),
            [
                'name' => 'text_input_background',
                'selector' => '{{WRAPPER}} input[type="text"]',
                'types' => [ 'classic', 'gradient']
            ]
        );

        $element->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name' => 'text_input_box_shadow',
                'selector' => '{{WRAPPER}} input[type="text"]'
            ]
        );

        $element->end_controls_tab();

        $element->start_controls_tab(
            'text_input_tab_hover_state',
            [
                'label' => __( 'Hover', 'auxin-elements' ),
            ]
        );

        $element->add_group_control(
            Group_Control_Background::get_type(),
            [
                'name' => 'text_input_background_hover',
                'selector' => '{{WRAPPER}} input[type="text"]:hover',
                'types' => [ 'classic', 'gradient']
            ]
        );

        $element->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name' => 'text_input_box_shadow_hover',
                'selector' => '{{WRAPPER}} input[type="text"]:hover'
            ]
        );

        $element->add_control(
            'text_input_transition',
            [
                'label' => __( 'Transition Duration', 'auxin-elements' ),
                'type' => Controls_Manager::SLIDER,
                'default' => [
                    'size' => 0.3,
                ],
                'range' => [
                    'px' => [
                        'max' => 3,
                        'step' => 0.1,
                    ],
                ],
                'render_type' => 'ui',
                'selectors' => [
                    '{{WRAPPER}} input[type="text"]' => "transition:all ease-out {{SIZE}}s;"
                ]
            ]
        );

        $element->end_controls_tab();

        $element->end_controls_tabs();
        // Background and Box Shadow for input - END

        $element->end_controls_section();

        /* -------------------------------------------------------------------------- */
        /* Email Input Style
        /* -------------------------------------------------------------------------- */

        $element->start_controls_section(
            'aux_core_email_input_section',
            [
                'label'      => __('Email Inputs', 'auxin-elements' ),
                'tab'       => Controls_Manager::TAB_STYLE
            ]
        );

        $element->add_control(
            'email_input_color',
            [
                'label' => __( 'Color', 'auxin-elements' ),
                'type' => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} input[type="email"]' => 'color: {{VALUE}};'
                ]
            ]
        );

        $element->add_responsive_control(
            'email_input_width',
            [
                'label' => __( 'Width', 'auxin-elements' ),
                'size_units' => [ 'px','em', '%'],
                'type' => Controls_Manager::SLIDER,
                'range' => [
                    'px' => [
                        'min' => 0,
                        'max' => 1000,
                        'step' => 5,
                    ],
                    '%' => [
                        'min' => 0,
                        'max' => 100,
                    ],
                    'em' => [
                        'min' => 0,
                        'max' => 100,
                    ],
                ],
                'selectors' => [
                    '{{WRAPPER}} input[type="email"]' => 'width: {{SIZE}}{{UNIT}};'
                ],
            ]
        );

        $element->add_responsive_control(
            'email_input_max_width',
            [
                'label' => __( 'Max Width', 'auxin-elements' ),
                'size_units' => [ 'px','em', '%'],
                'type' => Controls_Manager::SLIDER,
                'range' => [
                    'px' => [
                        'min' => 0,
                        'max' => 1000,
                        'step' => 5
                    ],
                    '%' => [
                        'min' => 0,
                        'max' => 100
                    ],
                    'em' => [
                        'min' => 0,
                        'max' => 100
                    ]
                ],
                'selectors' => [
                    '{{WRAPPER}} input[type="email"]' => 'max-width: {{SIZE}}{{UNIT}};'
                ]
            ]
        );

        $element->add_responsive_control(
            'email_input_height',
            [
                'label' => __( 'Height', 'auxin-elements' ),
                'size_units' => [ 'px', 'em'],
                'type' => Controls_Manager::SLIDER,
                'range' => [
                    'px' => [
                        'min' => 0,
                        'max' => 1000,
                        'step' => 5
                    ],
                    '%' => [
                        'min' => 0,
                        'max' => 100
                    ],
                    'em' => [
                        'min' => 0,
                        'max' => 100
                    ]
                ],
                'selectors' => [
                    '{{WRAPPER}} input[type="email"]' => 'height: {{SIZE}}{{UNIT}};'
                ],
                'separator' => 'after'
            ]
        );


        $element->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name' => 'email_input_border',
                'selector' => '{{WRAPPER}} input[type="email"]'
            ]
        );

        $element->add_responsive_control(
            'email_input_border_radius',
            [
                'label' => __( 'Border Radius', 'auxin-elements' ),
                'type' => Controls_Manager::DIMENSIONS,
                'size_units' => [ 'px', '%' ],
                'selectors' => [
                    '{{WRAPPER}} input[type="email"]' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'
                ],
            ]
        );

        $element->add_responsive_control(
            'email_input_padding',
            [
                'label' => __( 'Padding', 'auxin-elements' ),
                'type' => Controls_Manager::DIMENSIONS,
                'size_units' => [ 'px', '%', 'em' ],
                'selectors' => [
                    '{{WRAPPER}} input[type="email"]' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'
                ],
                'separator' => 'after'
            ]
        );

        // Background and Box Shadow for input - START
        $element->start_controls_tabs( 'email_input_tabs' );

        $element->start_controls_tab(
            'email_input_tab_normal_state',
            [
                'label' => __( 'Normal', 'auxin-elements' ),
            ]
        );

        $element->add_group_control(
            Group_Control_Background::get_type(),
            [
                'name' => 'email_input_background',
                'selector' => '{{WRAPPER}} input[type="email"]',
                'types' => [ 'classic', 'gradient']
            ]
        );

        $element->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name' => 'email_input_box_shadow',
                'selector' => '{{WRAPPER}} input[type="email"]'
            ]
        );

        $element->end_controls_tab();

        $element->start_controls_tab(
            'email_input_tab_hover_state',
            [
                'label' => __( 'Hover', 'auxin-elements' ),
            ]
        );

        $element->add_group_control(
            Group_Control_Background::get_type(),
            [
                'name' => 'email_input_background_hover',
                'selector' => '{{WRAPPER}} input[type="email"]:hover',
                'types' => [ 'classic', 'gradient']
            ]
        );

        $element->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name' => 'email_input_box_shadow_hover',
                'selector' => '{{WRAPPER}} input[type="email"]:hover'
            ]
        );

        $element->add_control(
            'email_input_transition',
            [
                'label' => __( 'Transition Duration', 'auxin-elements' ),
                'type' => Controls_Manager::SLIDER,
                'default' => [
                    'size' => 0.3,
                ],
                'range' => [
                    'px' => [
                        'max' => 3,
                        'step' => 0.1,
                    ],
                ],
                'render_type' => 'ui',
                'selectors' => [
                    '{{WRAPPER}} input[type="email"]' => "transition:all ease-out {{SIZE}}s;"
                ]
            ]
        );

        $element->end_controls_tab();

        $element->end_controls_tabs();

        $element->end_controls_section();

        /* -------------------------------------------------------------------------- */
        /* Dropdown Style
        /* -------------------------------------------------------------------------- */

        $element->start_controls_section(
            'aux_core_dropdown_section',
            [
                'label'      => __('Dropdown', 'auxin-elements' ),
                'tab'       => Controls_Manager::TAB_STYLE,
            ]
        );

        $element->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name' => 'dropdown_typography',
                'label' => __( 'Typography', 'auxin-elements' ),
                'global' => [
					'default' => Global_Typography::TYPOGRAPHY_PRIMARY,
				],
                'selector' => '{{WRAPPER}} select',
            ]
        );

        $element->add_control(
            'dropdown_color',
            [
                'label' => __( 'Color', 'auxin-elements' ),
                'type' => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} select' => 'color: {{VALUE}};',
                ]
            ]
        );

        $element->add_responsive_control(
            'dropdown_width',
            [
                'label' => __( 'Width', 'auxin-elements' ),
                'size_units' => [ 'px','em', '%'],
                'type' => Controls_Manager::SLIDER,
                'range' => [
                    'px' => [
                        'min' => 0,
                        'max' => 1000,
                        'step' => 5,
                    ],
                    '%' => [
                        'min' => 0,
                        'max' => 100,
                    ],
                    'em' => [
                        'min' => 0,
                        'max' => 100,
                    ],
                ],
                'selectors' => [
                    '{{WRAPPER}} select' => 'width: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $element->add_responsive_control(
            'dropdown_max_width',
            [
                'label' => __( 'Max Width', 'auxin-elements' ),
                'size_units' => [ 'px','em', '%'],
                'type' => Controls_Manager::SLIDER,
                'range' => [
                    'px' => [
                        'min' => 0,
                        'max' => 1000,
                        'step' => 5
                    ],
                    '%' => [
                        'min' => 0,
                        'max' => 100
                    ],
                    'em' => [
                        'min' => 0,
                        'max' => 100
                    ]
                ],
                'selectors' => [
                    '{{WRAPPER}} select' => 'max-width: {{SIZE}}{{UNIT}};',
                ]
            ]
        );

        $element->add_responsive_control(
            'dropdown_height',
            [
                'label' => __( 'Height', 'auxin-elements' ),
                'size_units' => [ 'px', 'em'],
                'type' => Controls_Manager::SLIDER,
                'range' => [
                    'px' => [
                        'min' => 0,
                        'max' => 1000,
                        'step' => 5
                    ],
                    '%' => [
                        'min' => 0,
                        'max' => 100
                    ],
                    'em' => [
                        'min' => 0,
                        'max' => 100
                    ]
                ],
                'selectors' => [
                    '{{WRAPPER}} select' => 'height: {{SIZE}}{{UNIT}};',
                ],
                'separator' => 'after'
            ]
        );


        $element->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name' => 'dropdown_border',
                'selector' => '{{WRAPPER}} select'
            ]
        );

        $element->add_responsive_control(
            'dropdown_border_radius',
            [
                'label' => __( 'Border Radius', 'auxin-elements' ),
                'type' => Controls_Manager::DIMENSIONS,
                'size_units' => [ 'px', '%' ],
                'selectors' => [
                    '{{WRAPPER}} select' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $element->add_responsive_control(
            'dropdown_padding',
            [
                'label' => __( 'Padding', 'auxin-elements' ),
                'type' => Controls_Manager::DIMENSIONS,
                'size_units' => [ 'px', '%', 'em' ],
                'selectors' => [
                    '{{WRAPPER}} select' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
                'separator' => 'after'
            ]
        );

        $element->add_responsive_control(
            'dropdown_margin',
            [
                'label' => __( 'Margin', 'auxin-elements' ),
                'type' => Controls_Manager::DIMENSIONS,
                'size_units' => [ 'px', '%', 'em' ],
                'selectors' => [
                    '{{WRAPPER}} select' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
                'separator' => 'after'
            ]
        );

        // Background and Box Shadow for input - START
        $element->start_controls_tabs( 'dropdown_input_tabs' );

        $element->start_controls_tab(
            'dropdown_tab_normal_state',
            [
                'label' => __( 'Normal', 'auxin-elements' ),
            ]
        );

        $element->add_group_control(
            Group_Control_Background::get_type(),
            [
                'name' => 'dropdown_background',
                'selector' => '{{WRAPPER}} select',
                'types' => [ 'classic', 'gradient']
            ]
        );

        $element->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name' => 'dropdown_box_shadow',
                'selector' => '{{WRAPPER}} select'
            ]
        );

        $element->end_controls_tab();

        $element->start_controls_tab(
            'dropdown_tab_hover_state',
            [
                'label' => __( 'Hover', 'auxin-elements' ),
            ]
        );

        $element->add_group_control(
            Group_Control_Background::get_type(),
            [
                'name' => 'dropdown_background_hover',
                'selector' => '{{WRAPPER}} select:hover',
                'types' => [ 'classic', 'gradient']
            ]
        );

        $element->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name' => 'dropdown_box_shadow_hover',
                'selector' => '{{WRAPPER}} select:hover'
            ]
        );

        $element->add_control(
            'dropdown_transition',
            [
                'label' => __( 'Transition Duration', 'auxin-elements' ),
                'type' => Controls_Manager::SLIDER,
                'default' => [
                    'size' => 0.3,
                ],
                'range' => [
                    'px' => [
                        'max' => 3,
                        'step' => 0.1,
                    ],
                ],
                'render_type' => 'ui',
                'selectors' => [
                    '{{WRAPPER}} select' => "transition:all ease-out {{SIZE}}s;"
                ]
            ]
        );

        $element->end_controls_tab();

        $element->end_controls_tabs();
        // Background and Box Shadow for input - END

        $element->end_controls_section();

        /* -------------------------------------------------------------------------- */
        /* Textarea Style
        /* -------------------------------------------------------------------------- */

        $element->start_controls_section(
            'aux_core_textarea_section',
            [
                'label'      => __('Textarea', 'auxin-elements' ),
                'tab'       => Controls_Manager::TAB_STYLE,
            ]
        );

        $element->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name' => 'textarea_typography',
                'label' => __( 'Typography', 'auxin-elements' ),
                'global' => [
					'default' => Global_Typography::TYPOGRAPHY_PRIMARY,
				],
                'selector' => '{{WRAPPER}} textarea',
            ]
        );

        $element->add_control(
            'textarea_color',
            [
                'label' => __( 'Color', 'auxin-elements' ),
                'type' => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} textarea' => 'color: {{VALUE}};',
                ]
            ]
        );

        $element->add_responsive_control(
            'textarea_width',
            [
                'label' => __( 'Width', 'auxin-elements' ),
                'size_units' => [ 'px','em', '%'],
                'type' => Controls_Manager::SLIDER,
                'range' => [
                    'px' => [
                        'min' => 0,
                        'max' => 1000,
                        'step' => 5,
                    ],
                    '%' => [
                        'min' => 0,
                        'max' => 100,
                    ],
                    'em' => [
                        'min' => 0,
                        'max' => 100,
                    ],
                ],
                'selectors' => [
                    '{{WRAPPER}} textarea' => 'width: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $element->add_responsive_control(
            'textarea_max_width',
            [
                'label' => __( 'Max Width', 'auxin-elements' ),
                'size_units' => [ 'px','em', '%'],
                'type' => Controls_Manager::SLIDER,
                'range' => [
                    'px' => [
                        'min' => 0,
                        'max' => 1000,
                        'step' => 5
                    ],
                    '%' => [
                        'min' => 0,
                        'max' => 100
                    ],
                    'em' => [
                        'min' => 0,
                        'max' => 100
                    ]
                ],
                'selectors' => [
                    '{{WRAPPER}} textarea' => 'max-width: {{SIZE}}{{UNIT}};',
                ]
            ]
        );

        $element->add_responsive_control(
            'textarea_height',
            [
                'label' => __( 'Height', 'auxin-elements' ),
                'size_units' => [ 'px', 'em'],
                'type' => Controls_Manager::SLIDER,
                'range' => [
                    'px' => [
                        'min' => 0,
                        'max' => 1000,
                        'step' => 5
                    ],
                    '%' => [
                        'min' => 0,
                        'max' => 100
                    ],
                    'em' => [
                        'min' => 0,
                        'max' => 100
                    ]
                ],
                'selectors' => [
                    '{{WRAPPER}} textarea' => 'height: {{SIZE}}{{UNIT}} !important;',
                ],
                'separator' => 'after'
            ]
        );

        $element->add_responsive_control(
            'textarea_border_radius',
            [
                'label' => __( 'Border Radius', 'auxin-elements' ),
                'type' => Controls_Manager::DIMENSIONS,
                'size_units' => [ 'px', '%' ],
                'selectors' => [
                    '{{WRAPPER}} textarea' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $element->add_responsive_control(
            'textarea_padding',
            [
                'label' => __( 'Padding', 'auxin-elements' ),
                'type' => Controls_Manager::DIMENSIONS,
                'size_units' => [ 'px', '%', 'em' ],
                'selectors' => [
                    '{{WRAPPER}} textarea' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
                'separator' => 'after'
            ]
        );

        $element->add_responsive_control(
            'textarea_margin',
            [
                'label' => __( 'Margin', 'auxin-elements' ),
                'type' => Controls_Manager::DIMENSIONS,
                'size_units' => [ 'px', '%', 'em' ],
                'selectors' => [
                    '{{WRAPPER}} textarea' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
                'separator' => 'after'
            ]
        );

        // Background and Box Shadow for input - START
        $element->start_controls_tabs( 'textarea_tabs' );

        $element->start_controls_tab(
            'textarea_tab_normal_state',
            [
                'label' => __( 'Normal', 'auxin-elements' ),
            ]
        );

        $element->add_group_control(
            Group_Control_Background::get_type(),
            [
                'name' => 'textarea_background',
                'selector' => '{{WRAPPER}} textarea',
                'types' => [ 'classic', 'gradient']
            ]
        );

        $element->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name' => 'textarea_box_shadow',
                'selector' => '{{WRAPPER}} textarea'
            ]
        );

        $element->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name' => 'textarea_border',
                'selector' => '{{WRAPPER}} textarea'
            ]
        );

        $element->end_controls_tab();

        $element->start_controls_tab(
            'textarea_tab_hover_state',
            [
                'label' => __( 'Hover', 'auxin-elements' ),
            ]
        );

        $element->add_group_control(
            Group_Control_Background::get_type(),
            [
                'name' => 'textarea_background_hover',
                'selector' => '{{WRAPPER}} textarea:hover',
                'types' => [ 'classic', 'gradient']
            ]
        );

        $element->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name' => 'textarea_box_shadow_hover',
                'selector' => '{{WRAPPER}} textarea:hover'
            ]
        );

        $element->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name' => 'textarea_border_hover',
                'selector' => '{{WRAPPER}} textarea:hover'
            ]
        );

        $element->add_control(
            'textarea_transition',
            [
                'label' => __( 'Transition Duration', 'auxin-elements' ),
                'type' => Controls_Manager::SLIDER,
                'default' => [
                    'size' => 0.3,
                ],
                'range' => [
                    'px' => [
                        'max' => 3,
                        'step' => 0.1,
                    ],
                ],
                'render_type' => 'ui',
                'selectors' => [
                    '{{WRAPPER}} textarea' => "transition:all ease-out {{SIZE}}s;"
                ]
            ]
        );

        $element->end_controls_tab();

        $element->start_controls_tab(
            'textarea_tab_focus_state',
            [
                'label' => __( 'Focus', 'auxin-elements' ),
            ]
        );

        $element->add_group_control(
            Group_Control_Background::get_type(),
            [
                'name' => 'textarea_background_focus',
                'selector' => '{{WRAPPER}} textarea:focus',
                'types' => [ 'classic', 'gradient']
            ]
        );

        $element->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name' => 'textarea_box_shadow_focus',
                'selector' => '{{WRAPPER}} textarea:focus'
            ]
        );

        $element->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name' => 'textarea_border_focus',
                'selector' => '{{WRAPPER}} textarea:focus'
            ]
        );

        $element->end_controls_tab();

        $element->end_controls_tabs();

        $element->end_controls_section();

        /* -------------------------------------------------------------------------- */
        /* Textarea Placeholder Style
        /* -------------------------------------------------------------------------- */

        $element->start_controls_section(
            'aux_core_textarea_placeholder_section',
            [
                'label'    => __('Textarea Placeholder', 'auxin-elements' ),
                'tab'      => Controls_Manager::TAB_STYLE
            ]
        );

        $element->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name' => 'textarea_placeholder_typography',
                'label' => __( 'Typography', 'auxin-elements' ),
                'global' => [
					'default' => Global_Typography::TYPOGRAPHY_PRIMARY,
				],
                'selector' => '{{WRAPPER}} textarea::placeholder'
            ]
        );

        $element->add_control(
            'textarea_placeholder_color',
            [
                'label' => __( 'Color', 'auxin-elements' ),
                'type' => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} textarea::placeholder' => 'color: {{VALUE}};',
                ]
            ]
        );

        $element->end_controls_section();

        /* -------------------------------------------------------------------------- */
        /* Labels                                                                     */
        /* -------------------------------------------------------------------------- */

        $element->start_controls_section(
            'aux_core_labels_section',
            [
                'label'      => __('Labels', 'auxin-elements' ),
                'tab'       => Controls_Manager::TAB_STYLE,
            ]
        );

        $element->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name' => 'labels_typography',
                'label' => __( 'Typography', 'auxin-elements' ),
                'global' => [
					'default' => Global_Typography::TYPOGRAPHY_PRIMARY,
				],
                'selector' => '{{WRAPPER}} label, {{WRAPPER}} legend'
            ]
        );

        $element->add_control(
            'labels_color',
            [
                'label' => __( 'Color', 'auxin-elements' ),
                'type' => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} label, {{WRAPPER}} legend' => 'color: {{VALUE}};'
                ]
            ]
        );

        $element->end_controls_section();

        /* -------------------------------------------------------------------------- */
        /* Submit Button Style
        /* -------------------------------------------------------------------------- */

        $element->start_controls_section(
            'aux_core_submit_input_section',
            [
                'label'      => __('Submit Button', 'auxin-elements' ),
                'tab'       => Controls_Manager::TAB_STYLE,
            ]
        );

        $element->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name' => 'submit_input_typography',
                'label' => __( 'Typography', 'auxin-elements' ),
                'global' => [
					'default' => Global_Typography::TYPOGRAPHY_PRIMARY,
				],
                'selector' => '{{WRAPPER}} button[type="submit"]',
            ]
        );

        $element->add_control(
            'submit_input_color',
            [
                'label' => __( 'Color', 'auxin-elements' ),
                'type' => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} button[type="submit"]:not(:hover):not(:active)' => 'color: {{VALUE}} !important;',
                ]
            ]
        );

        $element->add_responsive_control(
            'submit_input_width',
            [
                'label' => __( 'Width', 'auxin-elements' ),
                'size_units' => [ 'px','em', '%'],
                'type' => Controls_Manager::SLIDER,
                'range' => [
                    'px' => [
                        'min' => 0,
                        'max' => 1000,
                        'step' => 5,
                    ],
                    '%' => [
                        'min' => 0,
                        'max' => 100,
                    ],
                    'em' => [
                        'min' => 0,
                        'max' => 100,
                    ],
                ],
                'selectors' => [
                    '{{WRAPPER}} button[type="submit"]' => 'width: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $element->add_responsive_control(
            'submit_input_max_width',
            [
                'label' => __( 'Max Width', 'auxin-elements' ),
                'size_units' => [ 'px','em', '%'],
                'type' => Controls_Manager::SLIDER,
                'range' => [
                    'px' => [
                        'min' => 0,
                        'max' => 1000,
                        'step' => 5
                    ],
                    '%' => [
                        'min' => 0,
                        'max' => 100
                    ],
                    'em' => [
                        'min' => 0,
                        'max' => 100
                    ]
                ],
                'selectors' => [
                    '{{WRAPPER}} button[type="submit"]' => 'max-width: {{SIZE}}{{UNIT}};',
                ]
            ]
        );

        $element->add_responsive_control(
            'submit_input_height',
            [
                'label' => __( 'Height', 'auxin-elements' ),
                'size_units' => [ 'px', 'em'],
                'type' => Controls_Manager::SLIDER,
                'range' => [
                    'px' => [
                        'min' => 0,
                        'max' => 1000,
                        'step' => 5
                    ],
                    '%' => [
                        'min' => 0,
                        'max' => 100
                    ],
                    'em' => [
                        'min' => 0,
                        'max' => 100
                    ]
                ],
                'selectors' => [
                    '{{WRAPPER}} button[type="submit"]' => 'height: {{SIZE}}{{UNIT}};',
                ],
                'separator' => 'after'
            ]
        );


        $element->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name' => 'submit_input_border',
                'selector' => '{{WRAPPER}} button[type="submit"]'
            ]
        );

        $element->add_responsive_control(
            'submit_input_border_radius',
            [
                'label' => __( 'Border Radius', 'auxin-elements' ),
                'type' => Controls_Manager::DIMENSIONS,
                'size_units' => [ 'px', '%' ],
                'selectors' => [
                    '{{WRAPPER}} button[type="submit"]' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $element->add_responsive_control(
            'submit_input_padding',
            [
                'label' => __( 'Padding', 'auxin-elements' ),
                'type' => Controls_Manager::DIMENSIONS,
                'size_units' => [ 'px', '%', 'em' ],
                'selectors' => [
                    '{{WRAPPER}} button[type="submit"]' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;',
                ],
                'separator' => 'after'
            ]
        );

        $element->add_responsive_control(
            'submit_input_margin',
            [
                'label' => __( 'Margin', 'auxin-elements' ),
                'type' => Controls_Manager::DIMENSIONS,
                'size_units' => [ 'px', '%', 'em' ],
                'selectors' => [
                    '{{WRAPPER}} button[type="submit"]' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
                'separator' => 'after'
            ]
        );

        // Background and Box Shadow for input - START
        $element->start_controls_tabs( 'submit_input_tabs' );

        $element->start_controls_tab(
            'submit_input_tab_normal_state',
            [
                'label' => __( 'Normal', 'auxin-elements' ),
            ]
        );

        $element->add_group_control(
            Group_Control_Background::get_type(),
            [
                'name' => 'submit_input_background',
                'selector' => '{{WRAPPER}} button[type="submit"]:not(:hover):not(:active)',
                'types' => [ 'classic', 'gradient']
            ]
        );

        $element->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name' => 'submit_input_box_shadow',
                'selector' => '{{WRAPPER}} button[type="submit"]'
            ]
        );

        $element->end_controls_tab();

        $element->start_controls_tab(
            'submit_input_tab_hover_state',
            [
                'label' => __( 'Hover', 'auxin-elements' ),
            ]
        );


        $element->add_control(
            'submit_input_color_hover',
            [
                'label' => __( 'Color', 'auxin-elements' ),
                'type' => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} button[type="submit"]:hover' => 'color: {{VALUE}} !important;',
                ]
            ]
        );

        $element->add_group_control(
            Group_Control_Background::get_type(),
            [
                'name' => 'submit_input_background_hover',
                'selector' => '{{WRAPPER}} button[type="submit"]:hover',
                'types' => [ 'classic', 'gradient']
            ]
        );

        $element->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name' => 'submit_input_box_shadow_hover',
                'selector' => '{{WRAPPER}} button[type="submit"]:hover'
            ]
        );

        $element->add_control(
            'submit_input_transition',
            [
                'label' => __( 'Transition Duration', 'auxin-elements' ),
                'type' => Controls_Manager::SLIDER,
                'default' => [
                    'size' => 0.3,
                ],
                'range' => [
                    'px' => [
                        'max' => 3,
                        'step' => 0.1,
                    ],
                ],
                'render_type' => 'ui',
                'selectors' => [
                    '{{WRAPPER}} button[type="submit"]' => "transition:all ease-out {{SIZE}}s;"
                ]
            ]
        );

        $element->end_controls_tab();

        $element->end_controls_tabs();
        // Background and Box Shadow for input - END

        $element->end_controls_section();
}
add_action( 'elementor/element/wpforms/themes/after_section_end', 'auxin_extend_wpforms_elementor_widget', 10, 2 );
