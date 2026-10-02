/* addLoadEvent function by Simon Willison
http://www.webreference.com/programming/javascript/onloads/ */
function agp_addLoadEvent(func) {
  var oldonload = window.onload;
  if (typeof window.onload != 'function') {
    window.onload = func;
  } else {
    window.onload = function() {
      if (oldonload) {
        oldonload();
      }
      func();
    }
  }
}

addLoadEvent(function() {
	//on page load, hide any elements which were marked with a special class name  
	agp_display_marked_elements('div', 'agp_js_hide', 'none');
	agp_display_marked_elements('div', 'agp_js_show', 'block');
	agp_display_marked_elements('ul', 'agp_js_hide', 'none');
	agp_display_marked_elements('ul', 'agp_js_show', 'block');
	agp_display_marked_elements('li', 'agp_js_hide', 'none');
	agp_display_marked_elements('li', 'agp_js_show', 'block');
});

function agp_display_marked_elements(tag_name, class_name, display_mode) {
	var elems = document.getElementsByTagName(tag_name);

	if ( ! elems)
		return;
	
	for (var i=0; i<elems.length; i++) {
		if ( elems[i].className.indexOf(class_name) !== -1 )
			elems[i].style.display = display_mode;
	}
}

function agp_display_if(display_id, selection_id, display) {
	var foo = document.getElementById(selection_id);
	if (foo) {
		var fie = document.getElementById(display_id);
		if (fie) {
			if ( ! display )
				display = 'block';
			
			if ( foo.checked ) {
				fie.style.display = display;
			} else {
				fie.style.display = "none";
			}
		}
	}
}

function agp_swap_display(show_id, hide_id, clicked_link_id, other_link_id, class_selected, class_unselected) {
	var show_elem = document.getElementById(show_id);
	if (show_elem) {
		show_elem.style.display = "block";
	}
	
	var hide_elem = document.getElementById(hide_id);
	if (hide_elem) {
		hide_elem.style.display = "none";
	}
	
	var clicked_link = document.getElementById(clicked_link_id);
	if ( clicked_link && class_selected ) {
		clicked_link.parentNode.setAttribute("class", class_selected);
		clicked_link.parentNode.setAttribute("className", class_selected);
	}
	
	var other_link = document.getElementById(other_link_id);
	if ( other_link && class_unselected ) {
		other_link.parentNode.setAttribute("class", class_unselected);
		other_link.parentNode.setAttribute("className", class_unselected);
	}
}

function agp_filter_ul(list_id, filter_entry, checkbox_id, links_id) {
    var listobj = document.getElementById(list_id);
    
    if (!listobj) return;
    if (!listobj.childNodes) return;

    if ( filter_entry ) {
    	filter_entry = ' ' + filter_entry.toLowerCase();
	}
    
	for ( var i in listobj.childNodes ) {
		 if ( listobj.childNodes[i].title ) {
		      if ( listobj.childNodes[i].title.indexOf(filter_entry) >= 0 ) {
		      	listobj.childNodes[i].style.display = "block";
			  } else {
				listobj.childNodes[i].style.display = "none";
		      }
	 	}
    }
 
    if ( listobj.parentNode.parentNode.style.display == "none" )
	    listobj.parentNode.parentNode.style.display = "block";
    
    var checkboxobj = document.getElementById(checkbox_id);
    if ( checkboxobj )
    	checkboxobj.checked = 'checked';
	
    if ( links_id ) {
	    var links_obj = document.getElementById(links_id);
	    if ( links_obj )
	    	links_obj.style.display = "block";
	}
}

/* note: default config if no num_parent_nodes arg: checkbox contained in label, li, and ul 
*/
function agp_check_by_name(elem_name, check_it, visibility_check, click_event, required_container_id, num_parent_nodes) {
	var elems = document.getElementsByTagName('input');
	
	if ( ! elems )
		return;
	
	if ( ! num_parent_nodes )
		var num_parent_nodes = 3;
		
	var checkbox_val = ( check_it ) ? 'checked' : '';

	var parent_node;
	var first_pass = true;
	
	for (var i=0; i< elems.length; i++) {
		if ( (elems[i].type == "checkbox") && (elems[i].name == elem_name) ) {
			parent_node = elems[i].parentNode;
			
			if ( num_parent_nodes > 1 ) {
				for ( var j = 1; i < num_parent_nodes; j++ ) {
					if ( ! parent_node.parentNode )
						break;
					
					parent_node = parent_node.parentNode;
				}
			}
		
			if ( required_container_id ) {
				if ( parent_node.parentNode.id != required_container_id )
					continue;
			}
			
			if ( visibility_check ) {
				 if ( parent_node.style.display == "none" )
					continue;
			}
			
			if ( first_pass && visibility_check ) {
				first_pass = false;
				
				if ( parent_node.parentNode.style.display == "none" || parent_node.parentNode.parentNode.style.display == "none" || parent_node.parentNode.parentNode.parentNode.style.display == "none" )
					break;
			}
			
			if ( checkbox_val && click_event ) {
				elems[i].checked = '';
				elems[i].click();
			} else {
				elems[i].checked = checkbox_val;
			}
		}
	}
}

/**
 * Keep list-search controls beside the view links when they fit. When they do
 * not, move them into the top table navigation so they share the row with
 * Bulk Actions instead of consuming a row of their own.
 */
document.addEventListener('DOMContentLoaded', function() {
	var wrappers = document.querySelectorAll('.revision-q, .revision-archive');

	Array.prototype.forEach.call(wrappers, function(wrapper) {
		var controls = wrapper.querySelector('.revisionary-list-header-controls');
		var views = controls ? controls.querySelector('.subsubsub') : null;
		var search = controls ? controls.querySelector('p.search-box') : null;
		var topNav = wrapper.querySelector('.tablenav.top');

		if (!controls || !views || !search || !topNav) {
			return;
		}

		search.classList.add('revisionary-list-responsive-search');

		var placeSearch = function() {
			var pagination;

			controls.appendChild(search);

			if (search.offsetTop > views.offsetTop) {
				pagination = topNav.querySelector('.tablenav-pages');
				if (pagination) {
					topNav.insertBefore(search, pagination);
				} else {
					topNav.appendChild(search);
				}
			}
		};

		placeSearch();

		if ('ResizeObserver' in window) {
			var lastWidth = wrapper.getBoundingClientRect().width;
			new ResizeObserver(function(entries) {
				var width = entries[0].contentRect.width;
				if (Math.abs(width - lastWidth) < 1) {
					return;
				}

				lastWidth = width;
				placeSearch();
			}).observe(wrapper);
		} else {
			window.addEventListener('resize', placeSearch);
		}
	});
});

/**
 * Keep the plugin footer within the viewport when admin notices or other
 * messages occupy space above the plugin wrapper. The original footer layout
 * allows for the wrapper's normal 10px top offset; only additional displacement
 * needs to be removed from its viewport-based minimum height.
 */
document.addEventListener('DOMContentLoaded', function() {
	var content = document.getElementById('wpbody-content');
	var wrapper = content && content.querySelector(':scope > .pressshack-admin-wrapper');
	var resizeObserver;
	var observedNotices = [];

	if (
		!content
		|| !wrapper
		|| !(
			document.body.classList.contains('revisionary-q')
			|| document.body.classList.contains('revisionary-archive')
		)
	) {
		return;
	}

	var updateFooterHeight = function() {
		var hasPrecedingNotice = Array.prototype.some.call(
			content.querySelectorAll('div.notice'),
			function(notice) {
				return !wrapper.contains(notice)
					&& Boolean(notice.compareDocumentPosition(wrapper) & Node.DOCUMENT_POSITION_FOLLOWING);
			}
		);
		var contentTop = content.getBoundingClientRect().top;
		var wrapperTop = wrapper.getBoundingClientRect().top;
		var preWrapperHeight = hasPrecedingNotice
			? Math.max(0, Math.round(wrapperTop - contentTop - 10))
			: 0;

		wrapper.style.setProperty('--revisionary-pre-wrapper-height', preWrapperHeight + 'px');
	};

	var observeNotices = function() {
		if (!resizeObserver) {
			return;
		}

		observedNotices.forEach(function(notice) {
			resizeObserver.unobserve(notice);
		});
		observedNotices = Array.prototype.slice.call(content.querySelectorAll('div.notice'));
		observedNotices.forEach(function(notice) {
			resizeObserver.observe(notice);
		});
	};

	if ('ResizeObserver' in window) {
		resizeObserver = new ResizeObserver(updateFooterHeight);
		observeNotices();
	}

	if ('MutationObserver' in window) {
		new MutationObserver(function() {
			observeNotices();
			window.requestAnimationFrame(updateFooterHeight);
		}).observe(content, { childList: true, subtree: true });
	}

	window.addEventListener('resize', updateFooterHeight);
	updateFooterHeight();
});
