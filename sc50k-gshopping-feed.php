<?php
/*
 * Plugin Name: Somar - Google Shopping XML
 * Description: Generates an XML feed to be submitted to Google Shopping
 * Version: 1.0.2
 * Author: Somar Comunicacao
 * Author URI: https://www.somarcomunicacao.com.br/
 * Text Domain: sc50k_gshopping_feed
 *
 */

if( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Set the paths
 */
define( 'SC50k_GSHF_PLUGIN_PATH', plugin_dir_path(__FILE__) );
define( 'SC50k_GSHF_PLUGIN_URL', plugin_dir_url(__FILE__) );

$upload_dir = wp_upload_dir();

define( 'SC50k_GSHF_UPLOADS_DIR_PATH', $upload_dir['basedir'] );
define( 'SC50k_GSHF_FEEDS_DIR_NAME', 'sc50k-feeds' );
define( 'SC50k_GSHF_FEEDS_DIR_PATH', SC50k_GSHF_UPLOADS_DIR_PATH.'/'.SC50k_GSHF_FEEDS_DIR_NAME.'/' );
define( 'SC50k_GSHF_FEED_FILE_NAME', 'g-shopping-feed.xml');
define( 'SC50k_GSHF_FEED_FILE_PATH', SC50k_GSHF_FEEDS_DIR_PATH . SC50k_GSHF_FEED_FILE_NAME);

/**
 * Update product XML after create, update 
 */
add_action( 'before_delete_post', 'sc50k_gshf_products_action' );
add_action( 'save_post', 'sc50k_gshf_products_action' );
function sc50k_gshf_products_action( $post_id ){

	// Prevent action if WooCommerce not active
	if ( ! class_exists( 'WooCommerce' ) ) {
	  return;
	}

	// Prevent action if post type is not product
	if ( get_post_type() != 'product' ) {
	  return;
	}

	$product = wc_get_product( $post_id );

	// Prevent action if has some problem with the product
	if ( !$product || empty($product) || is_null($product) ){
		return;
	}

	// Create UPLOADS FEED DIRECTORY, if it does not exist
	if ( ! is_dir( SC50k_GSHF_FEEDS_DIR_PATH ) ) {
		mkdir( SC50k_GSHF_FEEDS_DIR_PATH, 0755 );
	}

	// Create default XML feed file, if it does not exist
	if ( ! file_exists(SC50k_GSHF_FEED_FILE_PATH) ){
		sc50k_gshf_create_main_xml_file();

		// Add all products to XML
		$args = array(
		  'limit' => -1,
		  'status' => 'publish',
		);
		$products = wc_get_products( $args );

		if ( !empty($products) ){

			// Continue only if the action is save_post (create or update)
			if ( current_action() != 'save_post' ){
				return;
			}

			$xml_str = file_get_contents( SC50k_GSHF_FEED_FILE_PATH );
			$dom = new DOMDocument();
			$dom->loadXML( $xml_str );
			$xpath = new DOMXPath($dom);
			$channel = $dom->getElementsByTagName('channel')->item(0);

			foreach ($products as $key => $product) {

				$price_number = wc_get_price_including_tax( $product );

				// Check if the product has price && if its status is publish
				if ( sc50k_gshf_product_has_price( $price_number ) && $product->get_status() == 'publish' ){
					
					// If is a variable product, add a product node for each variation
					if ( $product->is_type( 'variable' ) ) {
						$product_variations = $product->get_available_variations();

						if ( !empty($product_variations) ){
							foreach ($product_variations as $key => $product_variation) {
								$product_variation = wc_get_product( $product_variation['variation_id'] );
								$item = sc50k_gshf_create_product_item_xml( $product_variation, $product_variation->get_id(), $dom );
								$channel->appendChild($item);
							}
						}
					}

					elseif ( $product->is_type( 'simple' ) ) {
						$item = sc50k_gshf_create_product_item_xml( $product, $post_id, $dom );
						$channel->appendChild($item);
					}
				}
			}

			// Remove old file
			unlink( SC50k_GSHF_FEED_FILE_PATH );

			// Save new file
			$dom->save(SC50k_GSHF_FEED_FILE_PATH);
			chmod(SC50k_GSHF_FEED_FILE_PATH, 0774);
		}
	}

	else {

		// Remove product from XML 
		sc50k_gshf_remove_product_from_xml_file( $product, $post_id );

		// Add product to XML if is create || update product
		if ( current_action() == 'save_post' ){
			$price_number = wc_get_price_including_tax( $product );

			if ( 
				sc50k_gshf_product_has_price( $price_number ) && 
				$product->get_status() == 'publish' && 
				($price_number !== false && !empty($price_number) && !is_null($price_number)) 
			){
				sc50k_gshf_add_product_to_xml_file( $product, $post_id );
			}
		}
	}
}

/**
 * Create the main XML file without products (only structure)
 * 
 * @link https://support.google.com/merchants/answer/7052112
 * @return void
 */
function sc50k_gshf_create_main_xml_file(){

	// Create XML main node
	$dom = new DOMDocument();
	$dom->xmlVersion = '1.0';
	$dom->formatOutput = true;

	// Create RSS node
	$rss = $dom->createElement('rss');
	$rss->setAttribute("xmlns:g", "http://base.google.com/ns/1.0");
	$rss->setAttribute("version", "2.0");

	// Create channel node
	$channel = $dom->createElement('channel');
	$rss->appendChild($channel);

	// Channel title node
	$channel_title = $dom->createElement('title', 'MainProductsList');
	$channel->appendChild($channel_title);

	// Channel link node
	$channel_link = $dom->createElement('link', get_bloginfo('url'));
	$channel->appendChild($channel_link);

	// Description link node
	$channel_description = $dom->createElement('description', 'MainProductsList');
	$channel->appendChild($channel_description);

	$dom->appendChild($rss);
	$dom->save(SC50k_GSHF_FEED_FILE_PATH);
	chmod(SC50k_GSHF_FEED_FILE_PATH, 0774);
}

/**
 * Remove a product from XML file
 * 
 * @param object $product WC_Product object
 * @param int $post_id The current post ID
 * @return void
 */
function sc50k_gshf_remove_product_from_xml_file( $product, $post_id ){

	if ( !$product ){
		return;
	}

	$xml_str = file_get_contents( SC50k_GSHF_FEED_FILE_PATH );
	if ( empty( $xml_str ) ){
		return;
	}

	$xml_data = new SimpleXMLElement($xml_str);

	if ( $product->is_type( 'variable' ) ) {

		$product_variations = $product->get_available_variations();

		if ( !empty($product_variations) ){
			foreach ($product_variations as $key => $product_variation) {

				$nodes = $xml_data->xpath('//rss/channel/item/g:id[.="'.$product_variation['variation_id'].'"]/parent::*');

				if ( !empty($nodes) ){
					$node = $nodes[0];

					if ( ! empty($node) ) {
				    unset($node[0][0]);
					}
				}
			}
		}
	}

	elseif ( $product->is_type( 'simple' ) ) {

		$nodes = $xml_data->xpath('//rss/channel/item/g:id[.="'.$post_id.'"]/parent::*');

		if ( !empty($nodes) ){
			$node = $nodes[0];

			if ( ! empty($node) ) {
		    unset($node[0][0]);
			}
		}
	}

	// Remove old XML file
	unlink( SC50k_GSHF_FEED_FILE_PATH );

	// Save new XML
	$xml_data->asXml( SC50k_GSHF_FEED_FILE_PATH );
	chmod(SC50k_GSHF_FEED_FILE_PATH, 0774);
}

/**
 * Add a product to existing XML file
 *
 * @param object $product WC_PRODUCT object
 * @param int $post_id The current post ID
 * @return void
 */
function sc50k_gshf_add_product_to_xml_file( $product, $post_id ){
	
	$xml_str = file_get_contents( SC50k_GSHF_FEED_FILE_PATH );
	if ( empty( $xml_str ) ){
		return;
	}

	$dom = new DOMDocument();
	$dom->loadXML( $xml_str );
	$xpath = new DOMXPath($dom);
	$channel = $dom->getElementsByTagName('channel')->item(0);

	if ( $product->is_type( 'variable' ) ) {
		$product_variations = $product->get_available_variations();

		if ( !empty($product_variations) ){
			foreach ($product_variations as $key => $product_variation) {
				$product_variation = wc_get_product( $product_variation['variation_id'] );
				$item = sc50k_gshf_create_product_item_xml( $product_variation, $product_variation->get_id(), $dom );
				$channel->appendChild($item);
			}
		}
	}

	elseif ( $product->is_type( 'simple' ) ) {
		$item = sc50k_gshf_create_product_item_xml( $product, $post_id, $dom );
		$channel->appendChild($item);
	}

	// Remove old XML file
	unlink( SC50k_GSHF_FEED_FILE_PATH );

	// Save new XML
	$dom->save(SC50k_GSHF_FEED_FILE_PATH);
}

/**
 * Add a product item node to XML file
 *
 * @param object $product WC_PRODUCT object
 * @param int $post_id The current post ID
 * @param object $dom The current post ID
 * @return void
 */
function sc50k_gshf_create_product_item_xml( $product, $post_id, $dom ){
	$item = $dom->createElement('item');

	$product_id = $dom->createElement( 'g:id', $post_id );
	$item->appendChild( $product_id );

	$product_name = '';
	if ( $product->get_type() == 'simple' ){
		$product_name = $product->get_title();
	} 
	elseif( $product->get_type() == 'variable' || $product->get_type() == 'variation' ) {
		$product_name = $product->get_formatted_name();

		$product_name = str_replace(')<span', ') | <span', $product_name);
		$product_name = str_replace('/span><span', '/span> | <span', $product_name);
		$product_name = strip_tags($product_name);
	}

	$title = $dom->createElement( 'title' );
	$title->appendChild( $dom->createCDATASection( $product_name ) );
	$item->appendChild( $title );

	$product_description = $product->get_description();
	if ( $product->get_type() == 'simple' ){
		$product_description = $product->get_description();
	}
	elseif( $product->get_type() == 'variable' || $product->get_type() == 'variation' ) {
		$parent_product = wc_get_product( $product->get_parent_id() );
		$product_description = $parent_product->get_description();
	}

	$description = $dom->createElement( 'g:description' );
	$description->appendChild( $dom->createCDATASection( $product_description ) );
	$item->appendChild( $description );

	$link = $dom->createElement( 'link' );
	$link->appendChild( $dom->createCDATASection( get_permalink( $post_id ) ) );
	$item->appendChild( $link );

	$product_types_list = '';
	if ( $product->get_type() == 'simple' ){
		$product_types_list = strip_tags(wc_get_product_category_list( $post_id, $sep = ', ', '', '' ));
	}
	elseif( $product->get_type() == 'variable' || $product->get_type() == 'variation' ) {
		$product_types_list = strip_tags(wc_get_product_category_list( $parent_product->get_id(), $sep = ', ', '', '' ));
	}
	
	$product_type = $dom->createElement( 'g:product_type' );
	$product_type->appendChild( $dom->createCDATASection( $product_types_list ) );
	$item->appendChild( $product_type );

	$google_product_category = $dom->createElement( 'g:google_product_category' );
	$item->appendChild( $google_product_category );

	$image_url = wp_get_attachment_url( $product->get_image_id() );
	$image_link = $dom->createElement( 'g:image_link' );
	$image_link->appendChild( $dom->createCDATASection( $image_url ) );
	$item->appendChild( $image_link );

	$condition = $dom->createElement( 'g:condition' );
	$condition->appendChild( $dom->createCDATASection( 'New' ) );
	$item->appendChild( $condition );

	$availability_text = 'in stock';
	if ( ! $product->is_in_stock() ) $availability_text = 'out of stock';
	$availability = $dom->createElement( 'g:availability', $availability_text );
	$item->appendChild( $availability );

	$price_number = wc_get_price_including_tax( $product );
	$price_number = number_format( $price_number, 2, '.', '' );

	$price = $dom->createElement( 'g:price', $price_number .' '. get_option( 'woocommerce_currency' ) );
	$item->appendChild( $price );

	$product_attributes = $product->get_attributes();

	$brands_arr = array();

	// Variable Product
	if( $product->get_type() == 'variable' || $product->get_type() == 'variation' ) {
		foreach ( $product_attributes as $key => $product_attribute ) {
			if ( $key == 'pa_marca' ){
				$brands_arr[] = $product_attribute;
			}
		}
	}

	if ( $product->get_type() == 'simple' || empty( $brands_arr ) ){
		$product_attributes = $product->get_attributes();
	}

	if ( !empty($product_attributes) ){
		foreach ( $product_attributes as $key => $product_attribute ) {
			if ( is_object($product_attribute) && $product_attribute->get_name() == 'pa_marca' ){
				$terms = $product_attribute->get_terms();
				if ( !empty($terms) ){
					foreach ($terms as $key => $term) {
						$brands_arr[] = $term->name;
					}
				}
			}
		}
	}

	if ( !empty($brands_arr) ){
		$terms_str = implode(', ', $brands_arr);

		$brand = $dom->createElement( 'g:brand' );
		$brand->appendChild( $dom->createCDATASection( $terms_str ) );
		$item->appendChild( $brand );
	}

	$sku = $product->get_sku();
	if ( !empty($sku) ){
		$mpn = $dom->createElement( 'g:mpn' );
		$mpn->appendChild( $dom->createCDATASection( $sku ) );
		$item->appendChild( $mpn );
	}

	return $item;
}

/**
 * Check if the product has a valid price
 * 
 * @param string $price
 */
function sc50k_gshf_product_has_price($price){
	if ( ( is_int($price) || is_float($price) ) && $price > 0 ){
		return true;
	} else {
		return false;
	}
}