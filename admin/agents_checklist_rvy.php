<?php
if (!empty($_SERVER['SCRIPT_FILENAME']) && basename(__FILE__) == basename(esc_url_raw($_SERVER['SCRIPT_FILENAME'])) )
	die();
 
define ('CURRENT_ITEMS_RVY', 'current');
define ('ELIGIBLE_ITEMS_RVY', 'eligible');
 
 // TODO: scale this down more, as it's overkill for Revisionary's usage
 class RevisionaryAgentsChecklist {
	public static function agents_checklist( $role_basis, $all_agents, $id_prefix = '', $stored_assignments = '', $args = '') {
		if ( empty($all_agents) )
			return;

		$key = array();
		
		// list current selections on top first
		if ( $stored_assignments )
			self::_agents_checklist_display( CURRENT_ITEMS_RVY, $role_basis, $all_agents, $id_prefix, $stored_assignments, $args, $key); 
		
		self::_agents_checklist_display( ELIGIBLE_ITEMS_RVY, $role_basis, $all_agents, $id_prefix, $stored_assignments, $args, $key); 

		echo '<div id="rvy-agents-checklist-spacer">&nbsp;</div>';

		if ( $key ) {
			if ( empty($args['suppress_extra_prefix']) )
				$id_prefix .= "_{$role_basis}";
		}
	}
	
	// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
	// stored_assignments[agent_id][inherited_from] = progenitor_assignment_id (note: this function treats progenitor_assignment_id as a boolean)
	static function _agents_checklist_display( $agents_subset, $role_basis, $all_agents, $id_prefix, $stored_assignments, $args, &$key) {
		$defaults = array( 
		'filter_threshold' => 10, 			'default_hide_threshold' => 20,		'caption_length_limit' => 20, 		'emsize_threshold' => 4
		);

		$args = (array) $args;
		foreach( array_keys( $defaults ) as $var ) {
			$$var = ( isset( $args[$var] ) ) ? $args[$var] : $defaults[$var];
		}

		global $is_IE;
		$ie_checkbox_style = ( $is_IE ) ? "height:1em" : '';
		
		if ( ! is_array($stored_assignments) ) $stored_assignments = array();

		$id_prefix .= "_{$role_basis}";
		
		$agent_count = array();
		
		$agent_count[CURRENT_ITEMS_RVY] = count($stored_assignments);
		
		$agent_count[ELIGIBLE_ITEMS_RVY] = count($all_agents) - count( $stored_assignments );
					
		$default_hide_filtered_list = ( $default_hide_threshold && ( $agent_count[$agents_subset] > $default_hide_threshold ) );
			
		// determine whether to show caption, show/hide checkbox and filter textbox
		$any_display_filtering = ($agent_count[CURRENT_ITEMS_RVY] > $filter_threshold) || ($agent_count[ELIGIBLE_ITEMS_RVY] > $filter_threshold);
		
		if ( $agent_count[$agents_subset] > $filter_threshold ) {
			$flt_checked = ( ! $default_hide_filtered_list ) ? " checked" : '';

			echo "<ul class='rs-list_horiz rvy-list-horiz'><li>"; // IE6 (at least) does not render label reliably without this

			echo "<input type='checkbox' name='rs-jscheck[]' value='" . esc_attr("validate_me_{$agents_subset}_{$id_prefix}") . "' " 
			. "id='" . esc_attr("chk_{$agents_subset}_{$id_prefix}") . "' " 
			. esc_attr($flt_checked) 
			. " onclick=\""
			. "agp_display_if('" . esc_attr("div_{$agents_subset}_{$id_prefix}") . "', this.id);"
			. "agp_display_if('" . esc_attr("chk-links_{$agents_subset}_{$id_prefix}") . "', this.id);"
			. "\" /> ";
			
			echo "<strong><label for='" . esc_attr("chk_{$agents_subset}_{$id_prefix}") . "'>";

			if (CURRENT_ITEMS_RVY == $agents_subset) {
				printf(esc_html__('show current users (%d)', 'revisionary'), esc_html($agent_count[$agents_subset]));
			} else {
				printf(esc_html__('show eligible users (%d)', 'revisionary'), esc_html($agent_count[$agents_subset]));
			}
			
			echo '</label></strong>';
			echo '</li>';
			
			$class = ($default_hide_filtered_list) ? '' : 'agp_js_show';
			
			echo "\r\n" . "<li class='rvy-available-agents'>&nbsp;&nbsp;<label for='" . esc_attr("flt_{$agents_subset}_{$id_prefix}") . "' id='" . esc_attr("lbl_flt_{$id_prefix}") . "'>";
			_e ( 'filter:', 'revisionary');

			echo " <input type='text' id='" . esc_attr("flt_{$agents_subset}_{$id_prefix}") . "' size='10' " 
			. "onkeyup=\""
			. "agp_filter_ul('" . esc_attr("list_{$agents_subset}_{$id_prefix}") . "', this.value, '" . esc_attr("chk_{$agents_subset}_{$id_prefix}") . "', '" . esc_attr("chk-links_{$agents_subset}_{$id_prefix}") . "');"
			. "\" />";

			echo "</label></li>";
			
			echo "<li class='" . esc_attr($class) . "' style='display:none' id='" . esc_attr("chk-links_{$agents_subset}_{$id_prefix}") . "'>";
		
			echo "\r\n" . "&nbsp;&nbsp;" . "<a href='javascript:void(0)' "
			. "onclick=\""
			. "agp_check_by_name('" . esc_attr($id_prefix) . "[]', true, true, false, '" . esc_attr("list_{$agents_subset}_{$id_prefix}") . "', 1);"
			. "\">";
			
			_e ('select', 'revisionary');
			echo '</a>&nbsp;&nbsp;';
			
			echo "\r\n" . "<a href='javascript:void(0)' "
			. "onclick=\""
			. "agp_check_by_name('" . esc_attr($id_prefix) . "[]', '', true, false, '" . esc_attr("list_{$agents_subset}_{$id_prefix}") . "', 1);"
			. "\">";

			esc_html_e( 'unselect', 'revisionary');
			echo "</a>";
			
			echo '</li></ul>';
		}
		
		if ( $any_display_filtering || $agent_count[$agents_subset] > $emsize_threshold ) {
			global $wp_locale;
			$rtl = ( isset($wp_locale) && ('rtl' == $wp_locale->text_direction) );
			
			// -------- determine required list item width -----------
			if ( $caption_length_limit > 40 )
				$caption_length_limit = 40;
			
			if ( $caption_length_limit < 10 )
				$caption_length_limit = 10;
			
			$longest_caption_length = 0;
			
			foreach( $all_agents as $agent ) {
				$id = $agent->ID;
				
				$role_assigned = isset($stored_assignments[$id]);
				
				switch ( $agents_subset ) {
					case CURRENT_ITEMS_RVY:
						if ( ! $role_assigned ) continue 2;
						break;
					default: //ELIGIBLE_ITEMS_RVY
						if ( $role_assigned ) continue 2;
				}
				
				$caption = $agent->display_name;

				if ( strlen($caption) > $longest_caption_length ) {
					if ( strlen($caption) >= $caption_length_limit )
						$longest_caption_length = $caption_length_limit + 2;
					else
						$longest_caption_length = strlen($caption);
				}
			}
			
			if ( $longest_caption_length < 10 )
				$longest_caption_length = 10;
			
			if ( defined( 'UI_EMS_PER_CHARACTER') )
				$ems_per_character = UI_EMS_PER_CHARACTER;
			else
				$ems_per_character = 0.85;
			
			$list_width_ems = $ems_per_character * $longest_caption_length;
			
			$ems_integer = intval($list_width_ems);
			$ems_half = ( ($list_width_ems - $ems_integer) >= 0.5 ) ? '_5' : '';
			
			$ul_class = "rs-agents_list_{$ems_integer}{$ems_half}";
			$hide_class = ( $default_hide_filtered_list && $agent_count[$agents_subset] > $filter_threshold ) ? 'agp_js_hide' : '';

			echo "\r\n" . "<div id='" . esc_attr("div_{$agents_subset}_{$id_prefix}") . "' class='" . esc_attr($hide_class) . "'>"
				. "<div class='rs-agents_emsized'>"
				. "<ul class='" . esc_attr($ul_class) . "' id='" . esc_attr("list_{$agents_subset}_{$id_prefix}") . "'>";	
		} else {
			$ul_class = "rs-agents_list_auto";
			echo "\r\n<ul class='" . esc_attr($ul_class) . "' id='" . esc_attr("list_{$agents_subset}_{$id_prefix}") . "'>";
			$rtl = false;
		}
		//-------- end list item width determination --------------
	
		$last_agents = array();
		
		foreach( $all_agents as $agent ) {
			$id = $agent->ID;
			$agent_display_name = $agent->display_name;
			
			$role_assigned = isset($stored_assignments[$id]);
			
			switch ( $agents_subset ) {
				case CURRENT_ITEMS_RVY:
					if ( ! $role_assigned ) continue 2;
					break;
				default: //ELIGIBLE_ITEMS_RVY
					if ( $role_assigned ) continue 2;
			}
			
			$li_title = strtolower($agent_display_name);
			
			$this_checked = ( $role_assigned ) ? ' checked' : '';

			if ( $this_checked )
				$last_agents[] = $id;

			echo "\r\n<li title='" . esc_attr($li_title) . "'>"
				. "<input type='checkbox' name='" . esc_attr($id_prefix) . "[]'" . esc_attr($this_checked) . " value='" . esc_attr($id) . "' id='" . esc_attr("{$id_prefix}{$id}") . "' style='" . esc_attr($ie_checkbox_style) . "' />";
			
			echo "<label for='" . esc_attr("{$id_prefix}{$id}") . "'>";
			
			$caption = $agent_display_name;
			
			if ( strlen($caption) > $caption_length_limit ) {
				if ( ! empty($rtl) )
					$caption = '...' . substr( $caption, strlen($caption) - $caption_length_limit); 
				else
					$caption = substr($caption, 0, $caption_length_limit) . '...';
			}
			
			$caption = ' ' . $caption;
				
			echo esc_html($caption);
			echo '</label></li>';
			
		} //foreach agent
		
		echo "\r\n<li></li></ul>"; // prevent invalid markup if no other li's
		
		if ( CURRENT_ITEMS_RVY == $agents_subset ) {
			$last_agents = implode("~", $last_agents);
			echo "<input type='hidden' id='" . esc_attr("last_{$id_prefix}") . "' name='" . esc_attr("last_{$id_prefix}") . "' value='" . esc_attr($last_agents) . "' />";
		}
		
		if ( $any_display_filtering || $agent_count[$agents_subset] > $emsize_threshold ) 
			echo '</div></div>';
	}

} // end class
