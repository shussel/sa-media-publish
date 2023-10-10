<?php

/*
 
Plugin Name: Shane's Adventure Media Publish
 
Plugin URI: https://shanesadventure.com
 
Description: Plugin to allow publishing wp media files as posts
 
Version: 1.0
 
Author: Shane Hussel
 
Author URI: https://shanesadventure.com
 
License: GPLv2 or later
 
Text Domain: shanes-adventure
 
*/

/* SETTINGS */

// list of samp meta fields
function samp_meta_fields() {
	return [
		'_samp_date_taken',
		'_samp_date_featured',
		'_samp_license_number',
		'_samp_license_type',
		'_samp_timezone',
        '_samp_alt_og_image'
	];
}

// set default timezone for image
function samp_default_timezone( $post = null ) {
	return 'Atlantic/Reykjavik';
}

// set default photo root
function samp_photo_root() {
	return 'photo';
}

/* ACTIVATION */
register_activation_hook( __FILE__, 'samp_activate_plugin' );

function samp_activate_plugin() {
	// run daily cron at midnight EST
	if ( ! wp_next_scheduled( 'samp_daily_cron_hook' ) ) {
		wp_schedule_event( strtotime( '05:00:00' ), 'daily', 'samp_daily_cron_hook' );
	}
}

// cron hook
add_action( 'samp_daily_cron_hook', function () {
	// publish unpublished photos of the day
	$args = [
		'numberposts' => - 1,
		'post_status' => [ 'pending', 'inherit' ],
		'post_type'   => 'attachment',
		'meta_key'    => '_samp_date_featured',
		'meta_value'  => wp_date( 'Y-m-d' ),
	];
	if ( ( $posts = get_posts( $args ) ) && ( $post_count = count( $posts ) ) ) {
		foreach ( $posts as $post ) {
			samp_publish( $post->ID );
		}
	}
} );

/* DEACTIVATION */
register_deactivation_hook( __FILE__, 'samp_deactivate' );

function samp_deactivate() {
	// unschedule daily cron
	$timestamp = wp_next_scheduled( 'samp_daily_cron_hook' );
	wp_unschedule_event( $timestamp, 'samp_daily_cron_hook' );
}

/* URLS */

// query var for licensing
add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'license';

	return $vars;
} );

// remove unneeded rewrites
add_filter( 'rewrite_rules_array', function ( $rules ) {
	foreach ( $rules as $pattern => $rewrite ) {
		// remove default rules for attachments
		if ( preg_match( '/([?&]attachment=\$matches\[)/', $rewrite ) &&
		     ! preg_match( '/^' . samp_photo_root() . '\//', $pattern ) ) {
			unset( $rules[ $pattern ] );
		}
	}
	$custom_rules[ samp_photo_root() . '/(.?.+?)/?$' ] = 'index.php?attachment=$matches[1]';
	$custom_rules['photo-of-the-day/?$']               = 'index.php?post_type=attachment';
	$custom_rules['licensing/?([0-9]{1,})/?$']         = 'index.php?pagename=licensing&license=$matches[1]';

	return array_merge( $custom_rules, $rules );
} );

// customize attachment permalinks
add_filter( 'attachment_link', function ( $link, $post_id ) {
	$post = get_post( $post_id );
	$link = home_url( user_trailingslashit( samp_photo_root() . '/' . $post->post_name ) );

	return $link;
}, 10, 2 );

// link featured image in single post to attachment page
add_filter( 'post_thumbnail_html', function ( $html, $post_id, $post_image_id ) {
	if ( is_singular() && in_the_loop() && ( get_post_status( $post_image_id ) === 'publish' ) ) {
		return '<a href="' . get_permalink( $post_image_id ) . '">' . $html . '</a>';
	} else {
		return $html;
	}
}, 10, 3 );

/* SITEMAP */

// add attachments to post types site map
add_filter( 'wp_sitemaps_post_types', function ( $post_types ) {
	$post_types['attachment'] = get_post_type_object( 'attachment' );

	return $post_types;
} );

// new sitmap provider
class SAMP_Sitemaps_Provider extends WP_Sitemaps_Provider {

	public $name;

	public function __construct() {
		$this->name        = 'sa';
		$this->object_type = 'sa';
	}

	public function get_url_list( $page_num, $subtype = '' ) {

		$sitemap_entry = [
			'loc' => home_url( "/photo-of-the-day/" ),
		];
		$url_list[]    = $sitemap_entry;

		return $url_list;
	}

	public function get_max_num_pages( $subtype = '' ) {
		return 1;
	}
}

// register tags and sitemap provider for attachments
add_action( 'init', function () {
	register_taxonomy_for_object_type( 'post_tag', 'attachment' );
	// custom sitemaps
	$provider = new SAMP_Sitemaps_Provider();
	wp_register_sitemap_provider( 'sa', $provider );
}, 0 );

/* PUBLISHING */

// include pending and published posts in media list
add_action( 'pre_get_posts', function ( $query ) {
	// include pending and published in media list
	if ( is_admin() ) {
		if ( function_exists( 'get_current_screen' ) && get_current_screen()->base === 'upload' ) {

            // show pending and published images
			$arr   = explode( ',', $query->query["post_status"] );
			$arr[] = 'publish';
			$arr[] = 'pending';
			$query->set( 'post_status', implode( ',', $arr ) );

			// sort by featured date
            if ( ! isset( $_GET['orderby'] ) || '_potd' != $_GET['orderby'] ) {
				return;
			}
			$query->set('meta_key', '_samp_date_featured');
			$query->set('orderby', ['meta_value'=>$_GET['order'],'date'=>$_GET['order']]);
		}
	}
} );

/* Get true status of attachments
 *
 * WP always reports unattached attachments as status 'publish' if they are set to inherit
 * or an unknown status This function changes all calls to get_post_status for attachments
 * to report the true status including inherit, publish and our custom status of pending.
*/
add_filter( 'get_post_status', function ( $post_status, $post ) {
	if ( 'attachment' === $post->post_type ) {
		$post_status = $post->post_status;
	}

	return $post_status;
}, 11, 2 );

// define publish metabox
add_action( 'add_meta_boxes_attachment', function () {
	add_meta_box(
		'samp-publish-metabox', // Unique ID
		'Publish',    // Meta Box title
		'samp_publish_metabox_html',    // Callback function
		array( 'attachment', 'upload' ),                   // The selected post type
		'side',
		'high',
	);
} );

// display publish metabox
function samp_publish_metabox_html( $post ) {

	$meta = samp_get_photo_meta( $post->ID );

	$date_published = get_the_time( 'Y-m-d\TH:i:s', $post->ID );

	if ( $date_taken_gmt = $meta['image_meta']['created_timestamp'] ) {
		$date_taken_gmt = date( 'Y-m-d\TH:i:s', intval( $date_taken_gmt ) );
	}
	if ( $date_taken = $meta['date_taken'] ) {
		$date_taken = date( 'Y-m-d\TH:i:s', strtotime( $date_taken ) );
	}
	$date_featured = $meta['date_featured'];
	if ( ! $timezone = $meta['timezone'] ) {
		$timezone = samp_default_timezone();
	}
    $alt_og_image = $meta['alt_og_image'];

	wp_nonce_field( basename( __FILE__ ), 'samp_publish_metabox_nonce' );
	?>
    <div><?= $post->post_status ?>
        <label class="inline-label" for="samp-publish"><input type="checkbox" id="samp-publish" name="samp_publish"
                                                              value="<?= $post->post_status == 'publish' ? 'unpublish' : 'publish' ?>"><?= $post->post_status == 'publish' ? 'Unpublish' : 'Publish' ?>
        </label>

    </div>
    <div>
        <label for="samp-date-published">Published</label>
        <input id="samp-date-published" name="samp_date_published" type="datetime-local"
               value="<?php echo esc_attr( $date_published ); ?>">
    </div>
    <div>
        <label for="samp-date-taken-gmt">Date Taken GMT</label>
        <input id="samp-date-taken-gmt" name="samp_date_taken_gmt" type="datetime-local"
               value="<?php echo esc_attr( $date_taken_gmt ); ?>" readonly>
    </div>
    <div>
        <label for="samp-timezone">Timezone</label>
        <select name="samp_timezone" id="samp-timezone">
			<?php $tzlist = DateTimeZone::listIdentifiers( DateTimeZone::ALL );
			foreach ( $tzlist as $key => $value ) {
				echo '<option value="' . $value . '"' .
				     ( ( $timezone === $value ) ? ' selected' : '' ) . '>' . $value . '</option>';
			} ?> </select>
    </div>
    <div>
        <label for="samp-date-taken">Taken Local Time</label>
        <input id="samp-date-taken" name="samp_date_taken" type="datetime-local"
               value="<?php echo esc_attr( $date_taken ); ?>">
    </div>
    <div>
        <label for="samp-date-featured">Featured</label>
        <input id="samp-date-featured" name="samp_date_featured" type="date"
               value="<?php echo esc_attr( $date_featured ); ?>">
    </div>
    <div>
        <label for="samp-alt-og-image">Alt Feature Image</label>
        <input id="samp-alt-og-image" name="samp_alt_og_image" type="text"
               value="<?php echo esc_attr( $alt_og_image ); ?>">
    </div>
	<?php
}

// replace date column with publish in media list
add_filter( 'manage_upload_columns', function ( $columns ) {
	$new_columns = [];
	foreach ( $columns as $key => $column ) {
		if ( 'author' === $key ) {
			$new_columns['post_status'] = 'Publish';
		} elseif ( 'date' !== $key ) {
			$new_columns[ $key ] = $column;
		}
	}

	return $new_columns;
} );

// sort column by potd date
add_filter( 'manage_upload_sortable_columns', function ( $columns ) {
	$columns['post_status'] = '_potd';
	return $columns;
});

// display publish media list column
add_action( 'manage_media_custom_column', function ( $column_name, $post_id ) {
	if ( 'post_status' == $column_name ) {
		$status = get_post_status( $post_id );
		if ( 'pending' === $status ) {
			$action = 'publish';
			$class = 'future-time';
		} elseif ( 'inherit' === $status ) {
            $class = get_post_status(get_post_parent($post_id)) == 'publish' ? 'past-time' : 'pre-published';
			$action = 'publish';
		} elseif ( 'publish' === $status ) {
			$action = 'unpublish';
			$class = 'past-time';
		}
        echo '<div class="'. $class .'">' . $status . '</div>';
		if ( isset( $action ) ) {
			$link = wp_nonce_url( add_query_arg( array(
				'act' => $action,
				'itm' => $post_id
			), 'upload.php' ), 'publish_media_nonce' );
			printf( '<a href="%s">%s</a>', $link, __( ucfirst( $action ) ) );
		} ?>
        <p><?= get_the_date( 'Y-m-d g:ia', $post_id ) ?></p><?php
		if ( $featured_date = get_post_meta( $post_id, '_samp_date_featured', true ) ) {
            $class = strtotime($featured_date) > date('U') ? (( 'publish' === $status ) ? 'pre-published' : 'future-time') : 'past-time'; ?>
            <p class="<?=$class?>">POTD <?= $featured_date ?></p>
		<?php }
		if ( $license_number = get_post_meta( $post_id, '_samp_license_number', true ) ) { ?>
            <p><span class="past-time">$</span> License# <?= $license_number ?></p>
		<?php }
	}
}, 10, 2 );

// add bulk publish/unpublish actions
add_filter( 'bulk_actions-upload', function ( $bulk_actions ) {
	$bulk_actions['publish']   = __( 'Publish' );
	$bulk_actions['unpublish'] = __( 'Unpublish' );

	return $bulk_actions;
} );

// include pending/publish attachments in post add media popup
add_filter( 'ajax_query_attachments_args', function ( $query ) {
	$query['post_status'] .= ',publish,pending';

	return $query;
}, 11, 1 );

/* Adjust uploaded and edited attachments before saving
 *
 * New attachment uploads get status pending and prevent WP from changing status
 * values when updating. Post date is updated if set in Publish metabox.
*/
add_filter( 'wp_insert_attachment_data', function ( $data, $postarr, $unsanitized_postarr, $update ) {

	// new attachments
	if ( ! $update ) {
		if ( ! $data['post_parent'] ) {
			$data['post_status'] = 'pending';
		}
	} else {
		// keep original pending/publish status if set (WP will have changed to inherit)
		if ( in_array( $postarr['post_status'], [ 'pending', 'publish' ] ) ) {
			$data['post_status'] = $postarr['post_status'];
		}
		// publish checkbox
		if ( isset( $_REQUEST['samp_publish'] ) ) {
			if ( $_POST['samp_publish'] === 'publish' ) {
				$data['post_status'] = 'publish';
				// if both local and utc date taken values are set, use as publish date
				if ( ( $meta = samp_get_photo_meta( $postarr['post_ID'] ) ) &&
				     $meta['date_taken'] && $meta['image_meta']['created_timestamp'] ) {
					$data['post_date']     = date( 'Y-m-d H:i:s', strtotime( $meta['date_taken'] ) );
					$data['post_date_gmt'] = date( 'Y-m-d H:i:s', $meta['image_meta']['created_timestamp'] );
				} else {
					$data['post_date']     = current_time( 'mysql' );
					$data['post_date_gmt'] = get_gmt_from_date( $data['post_date'] );
				}
			} else {
				if ( $data['post_parent'] ) {
					$data['post_status'] = 'inherit';
				} else {
					$data['post_status'] = 'pending';
				}
			}
			// save updated post date
		} elseif ( isset( $_REQUEST['samp_date_published'] ) ) {
			$data['post_date']     = date( 'Y-m-d H:i:s', strtotime( $_POST['samp_date_published'] ) );
			$data['post_date_gmt'] = get_gmt_from_date( $data['post_date'] );
		}
	}

	return $data;
}, 99, 4 );

// save changes in publish metabox
add_action( 'edit_attachment', function ( $post_id ) {

	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// save publish metabox if nonce matches
	if ( isset( $_POST['samp_publish_metabox_nonce'] ) && wp_verify_nonce( $_POST['samp_publish_metabox_nonce'], basename( __FILE__ ) ) ) {
		// set local date taken if possible
		if ( ! $_REQUEST['samp_date_taken'] && $_REQUEST['samp_timezone'] && $_REQUEST['samp_date_taken_gmt'] ) {
			if ( $datetime_local = wp_date( 'Y-m-d H:i:s', strtotime( $_POST['samp_date_taken_gmt'] ), new DateTimeZone( $_POST['samp_timezone'] ) ) ) {
				update_post_meta( $post_id, '_samp_date_taken', sanitize_text_field( $datetime_local ) );
			}
		} elseif ( isset( $_REQUEST['samp_date_taken'] ) ) {
			update_post_meta( $post_id, '_samp_date_taken', sanitize_text_field( $_POST['samp_date_taken'] ? date( 'Y-m-d H:i:s', strtotime( $_POST['samp_date_taken'] ) ) : '' ) );
		}
		if ( isset( $_REQUEST['samp_timezone'] ) ) {
			if ($input = sanitize_text_field( $_POST['samp_timezone'] )) {
				update_post_meta( $post_id, '_samp_timezone', $input );
			} else {
				delete_post_meta( $post_id, '_samp_timezone');
			}
		}
		if ( isset( $_REQUEST['samp_date_featured'] ) ) {
			if ($input = sanitize_text_field( $_POST['samp_date_featured'] ? date( 'Y-m-d', strtotime( $_POST['samp_date_featured'] ) ) : '' )) {
				update_post_meta( $post_id, '_samp_date_featured', $input );
			} else {
				delete_post_meta( $post_id, '_samp_date_featured');
			}
		}
		if ( isset( $_REQUEST['samp_alt_og_image'] ) ) {
			if ($input = sanitize_text_field( $_POST['samp_alt_og_image'] )) {
				update_post_meta( $post_id, '_samp_alt_og_image', $input );
			} else {
				delete_post_meta( $post_id, '_samp_alt_og_image');
			}
		}
	}
} );

// handle row publish/unpublish actions
add_action( 'load-upload.php', function () {
	// Handle publishing only for admin media page
	if ( ! is_admin() || get_current_screen()->base !== 'upload' ) {
		return;
	}
	if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'publish_media_nonce' ) ) {
		$publish = $_GET['act'] === 'publish' ? true : false;
		$result  = samp_publish( $_GET['itm'], $publish );
	}
} );

// handle bulk publish/unpublish actions
add_filter( 'handle_bulk_actions-upload', function ( $redirect_to, $doaction, $post_ids ) {
	if ( ( $doaction !== 'publish' ) && ( $doaction !== 'unpublish' ) ) {
		return $redirect_to;
	}
	$publish = ( $doaction === 'publish' ) ? true : false;
	foreach ( $post_ids as $post_id ) {
		$result = samp_publish( $post_id, $publish );
	}

	return $redirect_to;
}, 10, 3 );

/* publish or unpublish an attachment
 *
 * Publish, pending or inherit status is set for attachment.
 * If date taken is set, use that for published date.
 */
function samp_publish( $attachment_id, $publish = true ) {

	// only attachment posts can be published with this
	if ( ! $attachment_id || ! ( $attachment = get_post( $attachment_id ) ) ||
	     ( 'attachment' !== $attachment->post_type ) ) {
		return false;
	}
	// publish
	if ( $publish ) {
		// if both local and utc date taken values are set, use as publish date
		if ( ( $meta = samp_get_photo_meta( $attachment_id ) ) &&
		     $meta['date_taken'] && $meta['image_meta']['created_timestamp'] ) {
			$post_date     = date( 'Y-m-d H:i:s', strtotime( $meta['date_taken'] ) );
			$post_date_gmt = date( 'Y-m-d H:i:s', $meta['image_meta']['created_timestamp'] );
		} else {
			$post_date     = current_time( 'mysql' );
			$post_date_gmt = get_gmt_from_date( $post_date );
		}

		return wp_update_post( array(
			'ID'            => $attachment_id,
			'post_status'   => 'publish',
			'post_date'     => $post_date,
			'post_date_gmt' => $post_date_gmt
		) );
		// unpublish
	} else {
		return wp_update_post( array(
			'ID'          => $attachment_id,
			'post_status' => $attachment->post_parent ? 'inherit' : 'pending'
		) );
	}
}

/* PHOTO METADATA */

// do not strip meta tags from image upload
add_filter( "image_strip_meta", function ( $strip_meta ) {
	return false;
}, 10, 1 );

// set timezone and alt after adding new images
add_action( 'add_attachment', function ( $post_id ) {

	// add default timezone
	add_post_meta( $post_id, '_samp_timezone', sanitize_text_field( samp_default_timezone( $post_id ) ), true );

	// save caption as alt
	if ( $caption = wp_get_attachment_caption( $post_id ) ) {
		add_post_meta( $post_id, '_wp_attachment_image_alt', $caption );
	}
}, 99 );

// get combined array of attachment and custom meta info
function samp_get_photo_meta( $attachment_id = null ) {

	// return empty result if not an attachment
	if ( ( ! $attachment_id && ! ( $attachment_id = get_the_ID() ) ) ||
	     ( get_post_type( $attachment_id ) !== 'attachment' ) ) {
		return [];
	}
	// get regular image metas
	$metas = wp_get_attachment_metadata( $attachment_id );

	// get custom samp image metas
	foreach ( samp_meta_fields() as $meta ) {
		$key           = str_replace( '_samp_', '', $meta );
		$metas[ $key ] = get_post_meta( $attachment_id, $meta, true );
	}
	// update datetime local
	if ( ! $metas['date_taken'] && $metas['timezone'] && $metas['image_meta']['created_timestamp'] ) {
		if ( $metas['date_taken'] = wp_date( 'Y-m-d H:i:s', $metas['image_meta']['created_timestamp'], new DateTimeZone( $metas['timezone'] ) ) ) {
			update_post_meta( $attachment_id, '_samp_date_taken', sanitize_text_field( $metas['date_taken'] ) );
		}
	}

	return $metas;
}

// get output friendly metadata with additional fields
function samp_get_photo_info( $attachment_id = null ) {

	// exit if no metadata found
	if ( ! $photo_info = samp_get_photo_meta( $attachment_id ) ) {
		return [];
	}
	// set aspect ratio
	$photo_info['aspect_ratio'] = $photo_info['width'] ? round( $photo_info['height'] / $photo_info['width'], 2 ) : false;

	// format image meta values
	if ( is_array( $photo_info['image_meta'] ) ) {
		foreach ( $photo_info['image_meta'] as $key => $value ) {
			switch ( $key ) {
				case 'shutter_speed':
					$value = $value ? '1/' . absint( 1 / $value ) . 's' : '';
					break;
				case 'focal_length':
					$value = $value ? $value . 'mm' : '';
					break;
				case 'aperture':
					$value = $value ? '&#119891;' . $value : '';
					break;
				case 'iso':
					$value = $value ? 'iso' . $value : '';
					break;
				case 'camera':
					$value = $value ? str_replace( ' ', '&nbsp;', $value ) : '';
					break;
				default:
			}
			$photo_info[ $key ] = $value;
		}
	}

	return $photo_info;
}

// output photo info
function samp_photo_info( $attachment_id = null ) {

	if ( ( ! $attachment_id && ! ( $attachment_id = get_the_ID() ) ) ||
	     ! ( $photo_info = samp_get_photo_info( $attachment_id ) ) ) {
		return;
	}
	?>
    <div class="photo-info">
        <div>
            <i class="sa-icon sa-icon-clock"></i><?= date( 'j-M-Y g:ia', strtotime( $photo_info['date_taken'] ) ) ?></div>
        <div>
            <i class="sa-icon sa-icon-camera"></i><?= $photo_info['camera'] ?> <?= $photo_info['focal_length'] ?>
        </div>
        <div>
            <i class="sa-icon sa-icon-half-circle"></i><?= $photo_info['aperture'] ?> <?= $photo_info['shutter_speed'] ?> <?= $photo_info['iso'] ?></div>
		<?php
		if ( $photo_info['license_number'] ) { ?>
            <div class="licensing has-text-align-center">This image is available
                for<a href="<?= home_url( user_trailingslashit( 'licensing/' . $photo_info['license_number'] ) ); ?>"> licensed use</a>.
            </div>
		<?php } ?>
    </div>
	<?php
}

/* PHOTO OF THE DAY */

// is view the potd page
function is_samp_potd() {
	global $wp;
	$url = parse_url(home_url( $wp->request ));
	return (!empty($url['path']) && ($url['path'] == '/photo-of-the-day'));
}

// add potd class
add_filter( 'body_class', function ( $classes, $class ) {
	if ( is_samp_potd() ) {
		$classes[] = 'potd';
	}

	return $classes;
}, 10, 2 );

// get all photos of the day in descending order
add_action( 'pre_get_posts', function ( $query ) {
	if ( is_samp_potd() && $query->is_main_query() ) {

		$query->set( 'meta_key', '_samp_date_featured' );
		$query->set( 'orderby', 'meta_value' );
		$query->set( 'order', 'desc' );
		$query->set( 'meta_query', array(
			array(
				'key'     => '_samp_date_featured',
				'value'   => '',
				'compare' => '!='
			),
			array(
				'key'     => '_samp_date_featured',
				'value'   => date('Y-m-d'),
				'compare' => '<'
			)
		) );
	}
} );

// gets post object for todays potd
function get_samp_potd( $args = [] ) {

    global $potd_shown;

	$args = array_merge( [
		'numberposts' => 1,
		'post_status' => 'publish',
		'post_type'   => 'attachment',
		'order_by'    => 'rand',
		'order'       => 'asc',
		'meta_key'    => '_samp_date_featured',
		'meta_value'  => wp_date( 'Y-m-d' ),
	], $args );
	if ( !$potd_shown && ($posts = get_posts( $args )) ) {
		$potd_shown = $posts[0]->ID;
		return $posts[0];
	}

	return null;
}

// get section html for todays potd
function samp_potd( $args = [] ) {

    $size = 'thumbnail';
    if (isset($args['size'])) {
        $size = $args['size'];
    }

	// exclude the current attachment post to not repeat potd in sidebar
	if ( is_single() && is_attachment() && get_queried_object_id() ) {
		$args['post__not_in'] = [ get_queried_object_id() ];
	}
	if ( $post = get_samp_potd( $args ) ) { ?>
        <section class="potd">
			<?php if ( is_samp_potd() ) { ?>
                <h2>Photo of the Day</h2>
			<?php } else { ?>
                <h2><a href="/photo-of-the-day/">Photo of the Day</a></h2>
			<?php } ?>
            <article id="post-<?= $post->ID ?>" <?php post_class( '', $post ) ?>>
				<?php echo get_the_post_thumbnail( $post, $size, ['loading'=>'eager'] ) ?>
            </article>
			<?php if ( ! is_samp_potd() ) { ?>
                <div class="read-more"><a href="/photo-of-the-day/">previous photos</a></div>
			<?php } ?>
        </section>
	<?php }
}

/* LICENSING */

// define license metabox
add_action( 'add_meta_boxes_attachment', function () {
	add_meta_box(
		'samp-license-metabox', // Unique ID
		'License',    // Meta Box title
		'samp_license_metabox_html',    // Callback function
		array( 'attachment', 'upload' ),                   // The selected post type
		'side',
		'high'
	);
} );

// display license metabox
function samp_license_metabox_html( $post ) {

	global $wpdb;

	$meta = samp_get_photo_meta( $post->ID );

	$license_number = $meta['license_number'];
	$license_type   = $meta['license_type'];

	$next_license_number = $wpdb->get_var("SELECT max(cast(meta_value as unsigned)) FROM wp_postmeta WHERE meta_key='_samp_license_number'") + 1;

	wp_nonce_field( basename( __FILE__ ), 'samp_license_metabox_nonce' );
	?>
    <div>
        <label for="samp-license-number">License Number</label>
        <input id="samp-license-number" name="samp_license_number" type="text"
               value="<?php echo esc_attr( $license_number ); ?>">
        <input id="samp-next-license-number" type="hidden" value="<?php echo esc_attr( $next_license_number ); ?>">
    </div>
    <div>
        <label for="samp-license-type">License Type</label>
        <input id="samp-license-type" name="samp_license_type" type="text"
               value="<?php echo esc_attr( $license_type ); ?>">
    </div>
	<?php
}

add_action('admin_print_styles-post.php', 'sa_license_js');
add_action('admin_print_styles-post-new.php', 'sa_license_js');

function sa_license_js() {
	wp_enqueue_script( 'license', get_template_directory_uri() . '/js/license.js', [], 1, true );
}

// save changes in license metabox
add_action( 'edit_attachment', function ( $post_id ) {

	global $wpdb;

	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	// save licence if nonce matches
	if ( isset( $_POST['samp_license_metabox_nonce'] ) && wp_verify_nonce( $_POST['samp_license_metabox_nonce'], basename( __FILE__ ) ) ) {
		if ( isset( $_REQUEST['samp_license_number'] ) ) {			;
            if ($input = sanitize_text_field( $_POST['samp_license_number'] )) {

                // prohibit existing license number
                if (get_posts(array(
	                'post_type' => 'attachment',
	                'post__not_in' => array($post_id),
                    'meta_key'    => '_samp_license_number',
		            'meta_value'  => $input,
	                'post_status' => 'any',
                ))) {
	                add_filter('redirect_post_location',function ($loc) {
		                return add_query_arg( 'sa_error_code', 111, $loc );
	                });
                    return;
                }
	            update_post_meta( $post_id, '_samp_license_number', $input );
            } else {
                delete_post_meta( $post_id, '_samp_license_number');
            }
		}
		if ( isset( $_REQUEST['samp_license_type'] ) ) {
			if ($input = sanitize_text_field( $_POST['samp_license_type'] )) {
				update_post_meta( $post_id, '_samp_license_type', $input );
			} else {
				delete_post_meta( $post_id, '_samp_license_type');
			}
		}
	}
} );

add_action( 'admin_init', function () {
    if (isset($_GET['sa_error_code']) && $_GET['sa_error_code']) {
	    add_action('admin_notices', function () {
		    switch ($_GET['sa_error_code']) {
			    case 111:
				    $sa_error_message = 'License number already exists';
				    break;
			    default:
				    $sa_error_message = 'Unknown error';
		    }
            echo '<div id="message" class="notice notice-error is-dismissible">
                <p>'.$sa_error_message. '</p>
            </div>';
	    },99);
    }
});

function samp_license_preview_image( $license_id ) {

	$args = array_merge( [
		'numberposts' => 1,
		'post_status' => 'publish',
		'post_type'   => 'attachment',
		'meta_key'    => '_samp_license_number',
		'meta_value'  => $license_id,
	] );
	if ( $posts = get_posts( $args ) ) { ?>
        <figure class="wp-block-image aligncenter size-medium">
            <a href="<?= get_attachment_link( $posts[0]->ID ) ?>">
				<?= wp_get_attachment_image( $posts[0]->ID, 'medium' ) ?>
            </a>
        </figure>
		<?php
	}
}

function samp_structured_image_data( $attachment = null ) {

	if (!is_attachment() || !( $attachment = get_post( $attachment ) ) || ! wp_attachment_is( 'image' ) || !($url = wp_get_attachment_image_url( $attachment->ID, 'full' )) ) {
		return;
	}

    $json = [
        '@context'   => "https://schema.org/",
        '@type'      => "ImageObject",
        'contentUrl' => $url,
        'license' => "https://shanesadventure.com/copyright/",
        'creditText' => "ShanesAdventure.com",
        'creator' => [
            "@type" => "Person",
            "name" => "Shane Hussel"
        ],
        "copyrightNotice" => "Shane Hussel"
    ];

    if ($license_number = get_post_meta($attachment->ID, '_samp_license_number', true)) {
        $json['acquireLicensePage'] = "https://shanesadventure.com/licensing/$license_number/";
    }

	if (has_term('', 'sa_destination', $attachment)) {
		$json['contentLocation'] = [
			"@type" => "Place",
			"name" => sa_get_full_destination(get_the_terms( $attachment, 'sa_destination' )[0])
		];
	}

    ?>
<script type="application/ld+json">
<?=wp_json_encode($json, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES )?>
</script>
    <?php
}

add_action( 'wp_head', 'samp_structured_image_data');

add_filter( 'media_library_infinite_scrolling', '__return_true' );

add_filter( 'media_view_settings', function( $settings ) {
	$settings['mimeTypes']['samp_potd'] = 'POTD';
	return $settings;
});

add_filter( 'views_upload', 'upload_views_filterable' );
function upload_views_filterable( $views ) {
	$views['something'] = 'test';
	return $views;
}
