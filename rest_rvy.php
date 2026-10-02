<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Revisionary_REST {
	var $request = '';
	var $method = '';
	var $is_view_method = false;
	var $endpoint_class = '';
	var $post_type = '';
	var $post_id = 0;
	var $operation = '';
	var $is_posts_request = false;
	
	function __construct() {
		add_filter( 'pp_rest_post_cap_requirement', array( $this, 'rest_post_cap_requirement' ), 10, 2 );
	}
	
	function rest_post_cap_requirement( $orig_cap, $item_id ) {
		if ( 'edit' == $this->operation ) {
			$post_type = get_post_field( 'post_type', $item_id );
			
			if ( $type_obj = get_post_type_object( $post_type ) ) {
				if ( $orig_cap == $type_obj->cap->read_post ) {
					$orig_cap = 'edit_post';
				}
			}
		}
	
		return $orig_cap;
	}
	
	function pre_dispatch( $rest_response, $rest_server, $request ) {
		$this->method = $request->get_method();
		$path   = $request->get_route();
		
		foreach ( $rest_server->get_routes() as $route => $handlers ) {
			if ( ! $match = @preg_match( '@^' . $route . '$@i', $path, $args ) )
				continue;

			foreach ( $handlers as $handler ) {
				if ( ! empty( $handler['methods'][ $this->method ] ) && is_array($handler['callback']) && is_object($handler['callback'][0]) ) {

					$this->endpoint_class = get_class( $handler['callback'][0] );
					
					$compatible_endpoints = apply_filters(
						'revisionary_rest_post_endpoints', 
						['WP_REST_Posts_Controller', 'LD_REST_Posts_Gutenberg_Controller']
					);

					if (!in_array($this->endpoint_class, $compatible_endpoints, true)) {
						continue;
					}

					$this->request = $request;

					$this->is_view_method = in_array( $this->method, array( WP_REST_Server::READABLE, 'GET' ), true );

					$post_id = self::get_id_element( $path );
								
					if (is_numeric($post_id)) {
						// back post type out of path because controller object does not expose it
						$type_base = $this->get_path_element( $path );
						
						$this->post_type = $this->get_type_from_rest_base( $type_base );
						$this->post_id = $post_id;
						$this->is_posts_request = true;
					}
				}
			}
		}
		
		return $rest_response;
	}  // end function pre_dispatch

	public static function get_id_element( $path, $position_from_right = 0 ) {
		$arr_path = explode( '/', $path );
		
		$count = -1;
		for( $i=count($arr_path) - 1; $i>=0; $i-- ) {
			$count++;
			
			if ( $count == $position_from_right )
				return $arr_path[$i];
		}
		
		return '';
	}
	
	function get_path_element( $path, $string_num_from_right = 1 ) {
		$arr_path = explode( '/', $path );
		
		$count = 0;
		for( $i=count($arr_path) - 1; $i>=0; $i-- ) {
			if ( is_numeric( $arr_path[$i] ) )
				continue;
			
			$count++;
			
			if ( $count == $string_num_from_right )
				return $arr_path[$i];
		}
		
		return '';
	}
	
	private function get_type_from_rest_base( $rest_base ) {
		if ( $types = get_post_types( array( 'rest_base' => $rest_base ) ) ) {
			$post_type = reset( $types );
			return $post_type;
		} elseif( post_type_exists( $rest_base ) ) {
			return $rest_base;
		} else {
			return false;
		}
	}
}
