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
use WP_Filesystem_Direct;

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
		/* translators: 1: name of the post */
		$this->app->write_log(sprintf(__( 'Starting export to Git for post %s.', 'pushpull' ), $name));
		$post = $this->app->utils()->getLocalPostByName($type, $name);
		if (!$post) {
			return new WP_Error( '404', esc_html__( 'Post not found', 'pushpull' ), array( 'status' => 404 ) );
		}
		$id = $this->push($post);
		$this->app->write_log(__( 'End export to Git.', 'pushpull' ));
		return $id;
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
		// Handle post images
		$imageids = $this->extract_imageids($post);
		foreach ($imageids as $imageid) {
			if ($imageid['id']) {
				$image = get_post($imageid['id']);
				$pushres = $this->create_commit($image);
				if ( is_wp_error( $pushres ) ) {
					$this->app->write_log($pushres);
					return $pushres;
				}
			}
		}

		// Handle featured image
		$meta = get_post_meta($post->ID);
		if (array_key_exists('_thumbnail_id', $meta)) {
			$image = get_post($meta['_thumbnail_id'][0]);
			$pushres = $this->create_commit($image);
			if ( is_wp_error( $pushres ) ) {
				$this->app->write_log($pushres);
				return $pushres;
			}
		}

		// Handle post
		$pushres = $this->create_commit($post);
		if ( is_wp_error( $pushres ) ) {
			$this->app->write_log($pushres);
			return $pushres;
		}

		$this->app->write_log(__( 'End export to Git.', 'pushpull' ));
		// Invalidate cache
		delete_transient('pushpull_remote_repo_files');

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
		$image_url = preg_replace("(^https?://)", "", $image_url);
		// No WP function to get image by URL and unable to cache
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$the_attachment = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE guid LIKE %s;", '%'.$image_url ));
		return empty($the_attachment) ? null : $the_attachment[0];
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
		$data['author'] = get_userdata($post->post_author)->user_login;

		// Meta
		$meta = [];
		foreach (get_post_meta($post->ID) as $key => $value) {
			// Use this filter hook to modify meta values before export
			// Returning False will delete the key
			// Returning True will keep the key
			// Returning a value will change the key
			$newvalue = apply_filters('pushpull_meta_'.$key, $value);
			if ($newvalue === False) {
				// Delete this meta key
				continue;
			} elseif ($newvalue === True) {
				// Keep this meta key
				$meta[$key] = $value[0];
				continue;
			} elseif ($newvalue !== $value) {
				// Change this meta key
				$meta[$key] = $newvalue;
				continue;
			}

			// Hard-coded filtering
			if ($key === "_edit_lock") {
				// Delete this meta key
				continue;
			}
			if ($key === "_thumbnail_id") {
				// Change the featured image ID into a post name
				$image = get_post($value[0]);
				$data['featuredimage'] = $image->post_name;
				continue;
			}
			if ($key === "_generate_element_display_conditions") {
				// Rewrite post IDs into post names
				$unserialized = maybe_unserialize($value[0]);
				foreach ($unserialized as $item => $displaycond) {
					if ($displaycond['rule'] === "post:page") {
						$tmppost = get_post($displaycond['object']);
						if ($tmppost) {
							$unserialized[$item]['object'] = $tmppost->post_type."/".$tmppost->post_name;
						}
					}
				}
				$meta[$key] = maybe_serialize($unserialized);
				continue;
			}
			// By default we keep the meta key and value
			$meta[$key] = $value[0];
		}
		$data['meta'] = $meta;
		$taxonomies = get_object_taxonomies($post->post_type);
		if (!empty($taxonomies)) {
			$terms = wp_get_object_terms($post->ID, $taxonomies);
			foreach ((array)$terms as $i => $term) {
				// Rewrite post IDs into post names for polylang post_translations taxonomies
				if ($term->taxonomy === 'post_translations') {
					$newvals = [];
					$description = maybe_unserialize($term->description);
					foreach($description as $lang => $id) {
						$tmppost = get_post($id);
						$newvals[$lang] = $tmppost->post_type."/".$tmppost->post_name;
					}
					$data['translations'] = maybe_serialize($newvals);
				} elseif ($term->taxonomy === 'language') {
					$data['language'] = $term->slug;
				} else {
					$data['terms'][] = $term;
				}
			}
		} else {
			$data['terms'] = [];
		}

		// If we have a media folder plugin, add location
		if ($post->post_type === "attachment" && function_exists('wp_attachment_folder')) {
			$folder = wp_rml_get_by_id(wp_attachment_folder($post->ID));
			if (is_rml_folder($folder)) {
				$data['folder'] = $folder->getName();
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

		// Use this filter hook to perform custom modifications to the data
		$data = apply_filters('pushpull_export', $data, $post);

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

		return $data;
	}

	/**
	 * Create a commit and push it to Git.
	 * If $post is an attachment, it will include all related media into the commit
	 *
	 * @param WP_Post $post
	 * @return mixed
	 */
	protected function create_commit(WP_Post $post) {
		$content = $this->create_post_export($post);
		$files = [];
		if (array_key_exists('meta', $content) && array_key_exists('_wp_attached_file', $content['meta'])) {
			// This is an attachment that references a file in uploads, we need to add it
			$fn = wp_upload_dir()['path']."/".$content['meta']['_wp_attached_file'];
			$wpfsd = new WP_Filesystem_Direct( false );
			$fc = $wpfsd->get_contents ( $fn );
			$files[] = [
				'action' => 'tbd', // Will be filled in later in GitLabProvider
				'file_path' => "_media/".$content['meta']['_wp_attached_file'],
				'content' => base64_encode($fc),
				'encoding' => "base64",
			];
		}

		$user = get_userdata(get_current_user_id());
		$wrap = [
			'branch' => 'tbd', // Will be filled in later in the provider
			'commit_message' => "PushPull Git export single post",
			'actions' => array_merge([[
				'action' => 'tbd', // Will be filled in later in GitLabProvider
				'file_path' => "_".$post->post_type."/".$post->post_name,
				'content' => wp_json_encode($content),
			]], $files),
			'author_email' => $user->user_email,
			'author_name' => $user->display_name,
		];

		$provider = get_option($this->app::PROVIDER_OPTION_KEY);
		$gitProvider = GitProviderFactory::createProvider($provider, $this->app);
		$res = $gitProvider->commit($wrap);

		return $res;
	}
}
