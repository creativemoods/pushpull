<?php
/**
 * Pusher.
 * @package PushPull
 */

namespace CreativeMoods\PushPull;

use DOMElement;
use WP_Error;
use WP_Post;
use DOMDocument;
use DOMNode;

/**
 * Class Pusher
 */
class Pusher {
	/**
	 * Application container.
	 *
	 * @var PushPull
	 */
	protected $app;

	/**
	 * Initializes a new import manager.
	 *
	 * @param PushPull $app Application container.
	 */
	public function __construct( PushPull $app ) {
		$this->app = $app;
	}

	/**
	 * Push a post and all its images and featured image
	 *
	 * @param string $type the type of post.
	 * @param string $name the name of the post.
	 *
	 * @return integer|WP_Error
	 */
	public function pushByName($type, $name) {
		$post = $this->app->utils()->getLocalPostByName($type, $name);
		if (!$post) {
			return new WP_Error( '404', esc_html__( 'Post not found', 'pushpull' ), array( 'status' => 404 ) );
		}
		$id = $this->push($post);

		return $id;
	}

	/**
	 * Push a table row
	 *
	 * @param string $plugin the name of the plugin that owns the table.
	 * @param string $table the name of the table.
	 * @param int $rowid the row id.
	 *
	 * @return integer|WP_Error
	 */
	public function pushTableRow($plugin, $table, $name) {
		if (has_filter('pushpull_default_tableimport_'.$plugin.'_'.$table.'_get_by_name')) {
			$localrow = apply_filters('pushpull_default_tableimport_'.$plugin.'_'.$table.'_get_by_name', $name);
			if ($localrow) {
				$this->app->write_log(__( 'Starting table row export to Git.', 'pushpull' ));

				$pushres = $this->create_tablerow_commit($plugin, $table, $localrow);
				if ( is_wp_error( $pushres ) ) {
					$this->app->write_log($pushres);
					return $pushres;
				}
	
				$this->app->write_log(__( 'End table row export to Git.', 'pushpull' ));
				return true;
			} else {
				return new WP_Error( '404', esc_html__( 'Row not found', 'pushpull' ), array( 'status' => 404 ) );
			}
		}
	}

	/**
	 * Push a post and all its images and featured image
	 *
	 * @param WP_Post $post Post to push.
	 *
	 * @return bool|WP_Error
	 */
	public function push( WP_Post $post ) {
		$this->app->write_log(__( 'Starting export to Git.', 'pushpull' ));
		$changes = [];

		// Handle post images
		$imageids = $this->extract_imageids($post);
		foreach ($imageids as $imageid) {
			if ($imageid['id']) {
				$image = get_post($imageid['id']);
				$changes = $changes + $this->create_post_commit_changes($image);
			}
		}

		// Handle featured image
		$meta = get_post_meta($post->ID);
		if (array_key_exists('_thumbnail_id', $meta)) {
			$image = get_post($meta['_thumbnail_id'][0]);
			if (!$image) {
				$this->app->write_log("Unable to get featured image ".$meta['_thumbnail_id'][0]);
			} else {
				$changes = $changes + $this->create_post_commit_changes($image);
			}
		}

		// Handle post
		$changes = $changes + $this->create_post_commit_changes($post);

		$this->app->state()->createCommit("Updated file: _".$post->post_type."/".$post->post_name, $changes);
		$this->app->write_log(__( 'End export to Git.', 'pushpull' ));

		return true;
	}

	/**
	 * Get image ID from URL
	 * 
	 * @source https://wordpress.stackexchange.com/questions/377301/how-do-you-find-a-file-in-the-media-library-using-the-file-url
	 * @param [type] $image_url
	 * 
	 * @return int|null
	 */
	protected function get_image_id($image_url) {
		global $wpdb;
		// Remove scheme
		$image_url = preg_replace("(^https?://)", "", $image_url);
		// Remove size
		$image_url = preg_replace("/-(\d+)x(\d+)\./", ".", $image_url);
		// No WP function to get image by URL and unable to cache
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$the_attachment = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE guid LIKE %s;", '%'.$image_url ));
		return empty($the_attachment) ? null : $the_attachment[0];
	}

	/**
	 * Create an export of a table row for storing in a file on Git
	 *
	 * @param string $plugin the name of the plugin that owns the table.
	 * @param string $table the name of the table.
	 * @return array $row the row data.
	 *
	 */
	public function create_tablerow_export(string $plugin, string $table, array $row) {
		$newvalue = [];

		if (has_filter('pushpull_tableexport_' . $plugin . '_' . $table)) {
			$newvalue = apply_filters('pushpull_tableexport_' . $plugin . '_' . $table, $row);
		} elseif (has_filter('pushpull_default_tableexport_' . $plugin . '_' . $table)) {
			// Call our default filter hook for this table
			$newvalue = apply_filters('pushpull_default_tableexport_' . $plugin . '_' . $table, $row);
		}

		return $newvalue;
	}

	/**
	 * Create an export of a post for storing in a file on Git
	 *
	 * @param WP_Post
	 * @return array
	 *
	 */
	public function create_post_export(WP_Post $post) {
		$data = [];
		$data['post_content'] = str_replace(get_home_url(), "@@DOMAIN@@", $post->post_content);
		$data['post_type'] = $post->post_type;
		$data['post_status'] = $post->post_status;
		$data['post_name'] = $post->post_name;
		$data['post_title'] = $post->post_title;
		$data['post_password'] = $post->post_password;
		$data['post_date'] = $post->post_date;
		$data['post_date_gmt'] = $post->post_date_gmt;
		$data['post_excerpt'] = $post->post_excerpt;
		// Manage post_parent
		if ($post->post_parent === 0) {
			$data['post_parent'] = null;
		} else {
			$parent = get_post($post->post_parent);
			$data['post_parent'] = $parent ? $parent->post_type."/".$parent->post_name : null;
		}
		$author = get_userdata($post->post_author);
		$data['author'] = $author ? $author->user_login : get_userdata(get_current_user_id())->user_login;

		// Meta
		$data['meta'] = [];
		foreach (get_post_meta($post->ID) as $key => $value) {
			// Use this filter hook to modify meta values before export
			// Returning False will delete the key
			// Returning True will keep the key
			// Returning a value will change the key
//			$this->app->write_log("Meta key: ".$key);
//			error_log(print_r($value, true));
//			$this->app->write_log($value);
			// get_post_meta returns an array of values, each value is exactly what is in the database
			// (
    		// 	[0] => a:1:{i:0;a:7:{s:2:"id";s:10:"pattern-77"
			// ...
			if (has_filter('pushpull_meta_' . $key)) {
				$newvalue = apply_filters('pushpull_meta_'.$key, $value);
				if ($newvalue === False) {
					// Delete this meta key
					continue;
				} elseif ($newvalue === True) {
					// Keep this meta key
					$data['meta'][$key] = $value;
					continue;
				} elseif ($newvalue !== $value) {
					// Change this meta key
					$data['meta'][$key] = $newvalue;
					continue;
				}
			} elseif (has_filter('pushpull_default_meta_' . $key)) {
				// Call our default filter hook for this meta key
				$newvalue = apply_filters('pushpull_default_meta_' . $key, $value);
				if ($newvalue === False) {
					// Delete this meta key
					continue;
				} elseif ($newvalue === True) {
					// Keep this meta key
					$data['meta'][$key] = $value;
					continue;
				} elseif ($newvalue !== $value) {
					// Change this meta key
					$data['meta'][$key] = $newvalue;
					continue;
				}
			}

			// Hard-coded filtering
			if ($key === "_edit_lock" || $key === "_encloseme") {
				// Delete this meta key
				continue;
			}
			if ($key === "_thumbnail_id") {
				// Change the featured image ID into a post name
				$image = get_post($value[0]);
				if ($image) {
					$data['featuredimage'] = $image->post_name;
				}
				continue;
			}
			if ($key === "_edit_last") {
				// Lookup username
				$user = get_userdata($value[0]);
				$data['meta'][$key] = $user->user_login;
				continue;
			}
			// By default we keep the meta key and value
			$data['meta'][$key] = $value;
		}

		// Taxonomies
		$taxonomies = get_object_taxonomies($post->post_type, 'names');
		$data['terms'] = [];
		foreach ($taxonomies as $taxonomy) {
			$terms = wp_get_object_terms($post->ID, $taxonomy);
			$data['terms'][$taxonomy] = [];
			foreach ((array)$terms as $i => $term) {
				// Use this filter hook to modify term values before export
				// Returning False will delete the key
				// Returning True will keep the key
				// Returning a value will change the value
				if (has_filter('pushpull_term_' . $term->taxonomy)) {
					// We call the 3rd party filter hook for this taxonomy if it exists
					$newvalue = apply_filters_ref_array('pushpull_term_' . $term->taxonomy, array($term, &$data));
					if ($newvalue === False) {
						// Delete this term
						continue;
					} elseif ($newvalue === True) {
						// Keep this term
						$data['terms'][$taxonomy][] = $term;
						continue;
					} elseif ($newvalue !== $value) {
						// Change this term
						$data['terms'][$taxonomy][] = $newvalue;
						continue;
					}
				} elseif (has_filter('pushpull_default_term_' . $term->taxonomy)) {
					// Call our default filter hook for this taxonomy
					$newvalue = apply_filters_ref_array('pushpull_default_term_' . $term->taxonomy, array($term, &$data));
					if ($newvalue === False) {
						// Delete this term
						continue;
					} elseif ($newvalue === True) {
						// Keep this term
						$data['terms'][$taxonomy][] = $term;
						continue;
					} elseif ($newvalue !== $value) {
						// Change this term
						$data['terms'][$taxonomy][] = $newvalue;
						continue;
					}
				} else {
					// No filter, keep the term
					$data['terms'][$taxonomy][] = [
						'slug' => $term->slug,
						'name' => $term->name,
					];
				}
			}
		}

		// Handle post images
		$imageids = $this->extract_imageids($post);
		$intimagelist = [];
		$extimagelist = [];
		foreach ($imageids as $imageid) {
			if ($imageid['id']) {
				$image = get_post($imageid['id']);
				$intimagelist[] = $image->post_name;
			} else {
				$extimagelist[] = $imageid['url'];
			}
		}
		$data['intimages'] = $intimagelist;
		$data['extimages'] = $extimagelist;

		// We find out registered plugins and apply one filter for each.
		// If you are a plugin maintainer and want PushPull to handle your
		// data, create a filter hook named pushpull_export_yourplugin
		$active_plugins = get_option('active_plugins');
		foreach ($active_plugins as $plugin) {
			$plugin = explode("/", $plugin)[0];
			if (has_filter('pushpull_export_' . $plugin)) {
				// We call the 3rd party filter hook if it exists
				$data = apply_filters('pushpull_export_' . $plugin, $data, $post);
			} else {
				// Call our default filter hook for this 3rd party plugin
				$data = apply_filters('pushpull_default_export_' . $plugin, $data, $post);
			}
		}

		ksort($data['meta']);
		return $data;
	}

	/**
	 * Find images in blocks code
	 *
	 * @param array $blocks
	 * @return array
	 */
	protected function find_bg_image_urls($blocks) {
		$urls = [];
		foreach ($blocks as $block) {
			// Check if the block has attributes and a bgImage attribute
			if (isset($block['attrs']) && isset($block['attrs']['bgImage'])) {
				$bgImage = $block['attrs']['bgImage'];
				// Check if bgImage has an image with a URL
				if (isset($bgImage['image']['url'])) {
					$urls[] = $bgImage['image']['url'];
				}
			}
			// Recursively search in innerBlocks if they exist
			if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
				$innerUrls = $this->find_bg_image_urls($block['innerBlocks']);
				$urls = array_merge($urls, $innerUrls);
			}
		}

		return $urls;
	}

	/**
	 * Cast DOMNode to DOMElement
	 * @source https://stackoverflow.com/questions/994102/domnode-to-domelement-in-php
	 *
	 * @param DOMNode $node
	 * 
	 * @return DOMElement|null
	 */
	private function cast_e(DOMNode $node) : DOMElement|null {
		if ($node) {
			if ($node->nodeType === XML_ELEMENT_NODE) {
				return $node;
			}
		}

		return null;
	}

	/**
	 * Extract image Ids from a WP_Post
	 * Returns a list of [id, url]
	 *
	 * @param WP_Post
	 * @return array
	 *
	 */
	protected function extract_imageids(WP_Post $post) {
		$data = [];
		$document = new DOMDocument();
		libxml_use_internal_errors(true);
		if ($post->post_content === "") {
			return $data;
		}
		$document->loadHTML($post->post_content);
		$images = $document->getElementsByTagName('img');
		foreach ($images as $image) {
			if ($image->nodeType === XML_ELEMENT_NODE) {
				$url = $this->cast_e($image)->getAttribute('src');
				$data[] = ['id' => $this->get_image_id($url), 'url' => $url];
			}
		}

		// Also look for images in blocks code
		$images = $this->find_bg_image_urls(parse_blocks($post->post_content));
		foreach ($images as $image) {
			$data[] = ['id' => $this->get_image_id($image), 'url' => $image];
		}

		//$this->app->write_log("Extracted image ids: ".json_encode($data));
		return $data;
	}

	/**
	 * Create changes for a commit.
	 * If $post is an attachment, it will include all related media into the commit
	 *
	 * @param WP_Post $post
	 * @return mixed
	 */
	protected function create_post_commit_changes(WP_Post $post) {
		global $wp_filesystem;

		$content = $this->create_post_export($post);
		$changes = [];
		if (array_key_exists('meta', $content) && array_key_exists('_wp_attached_file', $content['meta'])) {
			// This is an attachment that references a file in uploads, we need to add it
			$fn = wp_upload_dir()['path']."/".$content['meta']['_wp_attached_file'][0];
			$fc = $wp_filesystem->get_contents($fn);
			$name = "_media/".$content['meta']['_wp_attached_file'][0];
			$hash = $this->app->state()->saveFile($name, base64_encode($fc));
			$changes[$name] = $hash;
		}
		$name = "_".$post->post_type."/".$post->post_name;
		$hash = $this->app->state()->saveFile($name, wp_json_encode($content));
		$changes[$name] = $hash;

		return $changes;
	}

	/**
	 * Create a tablerow commit and push it to Git.
	 *
	 * @param array $row
	 * @return mixed
	 */
	// TODO apply same changes as with create_post_commit_changes
	 protected function create_tablerow_commit(string $plugin, string $table, array $row) {
		list($keyname, $content) = $this->create_tablerow_export($plugin, $table, $row);

		// Escape slashes in keyname (TODO in create_commit and in other providers)
		$keyname = str_replace("/", "@@SLASH@@", $keyname);

		$name = "_".$plugin."@".$table."/".$keyname;
		$hash = $this->app->state()->saveFile($name, wp_json_encode($content));
		$this->app->state()->createCommit("Updated file: $name", [$name => $hash]);

		return true;
	}
}
