<?php

/*
 * Plugin Name: WooCommerce Dynamic Pricing Category Blocks
 * Plugin URI: https://github.com/lucasstark/es-dynamic-pricing-category-blocks
 * Description: WooCommerce Dynamic Pricing Category Blocks creates block based pricing for categories.  Note, values are hardcoded in this plugin.
 * Version: 1.0.0
 * Author: Lucas Stark
 * Author URI: https://elementstark.com
 * Requires at least: 3.3
 * Tested up to: 4.9.4
 * Text Domain: woocommerce-dynamic-pricing
 * Domain Path: /i18n/languages/
 * Copyright: © 2009-2018 Lucas Stark.
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * WC requires at least: 3.0.0
 * WC tested up to: 3.3.4
 */

class ES_Dynamic_Pricing_Category_Blocks {

	/**
	 * @var ES_Dynamic_Pricing
	 */
	private static $instance;

	public static function register() {
		if ( self::$instance == null ) {
			self::$instance = new ES_Dynamic_Pricing_Category_Blocks();
		}
	}


	private $_cart_setup = false;

	private $_categories_to_adjust;
	private $_categories_to_count;
	private $_price_blocks;
	private $_repeat_last_block;

	protected function __construct() {

		add_filter( 'woocommerce_get_cart_item_from_session', array(
			$this,
			'on_woocommerce_get_cart_item_from_session'
		), 10, 3 );


		add_action( 'woocommerce_cart_loaded_from_session', array(
			$this,
			'on_cart_loaded_from_session'
		), 0, 1 );


		add_filter( 'woocommerce_product_get_price', array( $this, 'on_get_price' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_price', array( $this, 'on_get_cart_item_price' ), 10, 2 );

		$this->_categories_to_adjust = array( 60 );
		$this->_categories_to_count  = array( 60 );

		$this->_price_blocks    = array();
		$this->_price_blocks[1] = 0.10;
		$this->_price_blocks[2] = 0.50;
		$this->_price_blocks[3] = 0.75;

		$this->_repeat_last_block = true;
	}

	/**
	 * Records the cart item key on the product so we can reference it in the future.
	 *
	 * @param $cart_item
	 * @param $cart_item_values
	 * @param $cart_item_key
	 *
	 * @return mixed
	 */
	public function on_woocommerce_get_cart_item_from_session( $cart_item, $cart_item_values, $cart_item_key ) {
		//$cart_item['data']->cart_item_key = $cart_item_key;
		return $cart_item;
	}

	/**
	 * Setup the adjustments on the cart.
	 *
	 * @param WC_Cart $cart
	 */
	public function on_cart_loaded_from_session( $cart ) {
		$this->setup_cart( $cart );
	}


	/**
	 * @param WC_Cart $cart
	 */
	public function setup_cart( $cart ) {
		if ( $this->_cart_setup ) {
			return;
		}

		$block_index = 1;
		ksort( $this->_price_blocks, SORT_NUMERIC );
		$price_blocks = array_reverse( $this->_price_blocks, true );

		$category_count = 0;
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {

			unset( WC()->cart->cart_contents[ $cart_item_key ]['es_adjusted_quantities'] );

			$product_categories = $cart_item['data']->get_category_ids();
			if ( count( array_intersect( $product_categories, $this->_categories_to_count ) ) > 0 ) {
				$category_count += $cart_item['quantity'];
			}

		}


		if ( $cart && $cart->get_cart_contents_count() ) {

			foreach ( $cart->get_cart() as $cart_item_key => &$cart_item ) {

				$product    = $cart_item['data'];
				$product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

				$the_product            = wc_get_product( $product_id );
				$the_product_categories = $the_product->get_category_ids();
				$product_base_price = $product->get_price();

				if ( count( array_intersect( $this->_categories_to_adjust, $the_product_categories ) ) > 0 ) {

					//Record the cart item key so we can reference it in the get_price function.
					WC()->cart->cart_contents[ $cart_item_key ]['data']->cart_item_key = $cart_item_key;
					WC()->cart->cart_contents[ $cart_item_key ]['es_adjusted_quantities'] = array_fill( 1, $cart_item['quantity'], $product_base_price );

					for ( $i = 1; $i <= $cart_item['quantity']; $i ++ ) {

						$adjustment = false;
						if ( isset( $price_blocks[ $block_index ] ) ) {
							$adjustment = $price_blocks[ $block_index ];
						} else {
							if ( $this->_repeat_last_block ) {
								$adjustment = $price_blocks[ max( array_keys( $price_blocks ) ) ];
							}
						}

						if ( $adjustment ) {
							$product_price                                                              = $product_base_price;
							$adjusted_price                                                             = $product_price - ( $product_price * $adjustment );
							WC()->cart->cart_contents[ $cart_item_key ]['es_adjusted_quantities'][ $i ] = $adjusted_price;

							//Increase the block index.
							$block_index ++;
						}

					}

				}
			}
		}

		$this->_cart_setup = true;
	}

	/**
	 * Finally everything is all set we can get the product price for cart items.
	 *
	 * @param $price
	 * @param $product
	 */
	public function on_get_price( $price, $product ) {

		if ( isset( $product->cart_item_key ) ) {
			//We know this is for a product in the cart.

			$cart_item = WC()->cart->get_cart_item( $product->cart_item_key );

			if ( $cart_item && isset( $cart_item['es_adjusted_quantities'] ) ) {
				//found our cart item, and adjusted quantities.
				$price = wc_cart_round_discount( array_sum( $cart_item['es_adjusted_quantities']) / count( $cart_item['es_adjusted_quantities'] ), 4 );
			}

		}

		return $price;

	}


	//Format the price as a sale price.
	public function on_get_cart_item_price( $html, $cart_item ) {


		$result_html = false;

		if ( isset( $cart_item['data']->cart_item_key ) ) {


			if ( isset( $cart_item['es_adjusted_quantities'] ) && ! empty( $cart_item['es_adjusted_quantities'] ) ) {
				$result_html = '';

				$block_html = '';
				$amounts    = array();
				foreach ( $cart_item['es_adjusted_quantities'] as $adjusted_price ) {
					if ( ! isset( $amounts[ $adjusted_price ] ) ) {
						$amounts[ $adjusted_price ] = 0;
					}
					$amounts[ $adjusted_price ] = $amounts[ $adjusted_price ] + 1;
				}

				foreach ( $amounts as $amount => $quantity ) {
					$result_html .= wc_price( $amount ) . ' x ' . $quantity;
					$result_html .= '<br />';
				}

				$remaining = $cart_item['quantity'] - count( $cart_item['es_adjusted_quantities'] );
				if ( $remaining ) {
					$result_html .= wc_price( $cart_item['data']->get_price( 'edit' ) ) . ' x ' . $remaining;
				}

			}


		}


		return $result_html ? $result_html : $html;

	}

}

ES_Dynamic_Pricing_Category_Blocks::register();